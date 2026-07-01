<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DataFeed — ports data_feed.py
 *
 * Fetches price, yesterday's close, and ATR from Yahoo Finance
 * using the unofficial query2 JSON endpoint (no API key needed).
 *
 * Results are cached 30s (price) and 5min (ATR / yest close)
 * to avoid hammering Yahoo on every scheduler tick.
 */
class DataFeed
{
    private const PRICE_TTL  = 5;   // seconds - testing
    // private const PRICE_TTL  = 30;   //seconds- live

    private const HIST_TTL   = 300;  // seconds

    // ── Public bundle ─────────────────────────────────────────────────────

    /**
     * Fetch price, yest_close, ATR in one call.
     * Throws \RuntimeException on failure.
     */
    public static function fetchAll(string $ticker): array
    {
        $price     = static::fetchPrice($ticker);
        $yestClose = static::fetchYestClose($ticker);
        $atr       = static::fetchAtr($ticker);

        return [
            'price'      => round($price,     4),
            'yest_close' => round($yestClose, 4),
            'atr'        => round($atr,       4),
        ];
    }

    // ── Price ─────────────────────────────────────────────────────────────

  public static function fetchPrice(string $ticker): float
{
    $cacheKey = "datafeed_price_{$ticker}";

    return Cache::remember($cacheKey, static::PRICE_TTL, function () use ($ticker) {

        $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$ticker}";

        $response = Http::timeout(10)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'application/json',
            ])
            ->get($url, [
                'interval' => '1m',
                'range'    => '2d',
                'includePrePost' => 'true',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Yahoo Finance price fetch failed for {$ticker}: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        $result = $data['chart']['result'][0] ?? null;

        if (!$result) {
            throw new \RuntimeException("No chart result for {$ticker}");
        }

        $timestamps = $result['timestamp'] ?? [];

        $closes = $result['indicators']['quote'][0]['close'] ?? [];

        if (empty($closes)) {
            throw new \RuntimeException("No close prices for {$ticker}");
        }

        // Remove null candles
        $valid = [];

        foreach ($closes as $i => $close) {
            if ($close !== null) {
                $valid[] = [
                    'time' => $timestamps[$i] ?? null,
                    'price' => $close,
                ];
            }
        }

        if (empty($valid)) {
            throw new \RuntimeException("No valid candles for {$ticker}");
        }

        $latest = end($valid);

        Log::info('Yahoo latest tick', [
            'ticker' => $ticker,
            'time'   => $latest['time']
                ? date('Y-m-d H:i:s', $latest['time'])
                : null,
            'price'  => $latest['price'],
        ]);

        return (float) $latest['price'];
    });
}


public static function fetchDailyHistory(
    string $ticker,
    string $from,
    string $to
): array {

    $cacheKey = "history_{$ticker}_{$from}_{$to}";

    return Cache::remember($cacheKey, 3600, function () use ($ticker, $from, $to) {

        $period1 = strtotime($from);
        $period2 = strtotime($to);

        $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$ticker}";

        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0',
                'Accept' => 'application/json',
            ])
            ->get($url, [
                'period1' => $period1,
                'period2' => $period2,
                'interval' => '1d',
                'events' => 'history',
                'includeAdjustedClose' => 'true',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Yahoo history fetch failed: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        $result = $data['chart']['result'][0] ?? null;

        if (!$result) {
            throw new \RuntimeException("No history returned");
        }

        $timestamps = $result['timestamp'] ?? [];

        $quote = $result['indicators']['quote'][0] ?? [];

        $opens  = $quote['open'] ?? [];
        $highs  = $quote['high'] ?? [];
        $lows   = $quote['low'] ?? [];
        $closes = $quote['close'] ?? [];
        $vols   = $quote['volume'] ?? [];

        $rows = [];

        foreach ($timestamps as $i => $ts) {

            if (
                $opens[$i]  === null ||
                $highs[$i] === null ||
                $lows[$i]  === null ||
                $closes[$i] === null
            ) {
                continue;
            }

            $rows[] = [
                'date'   => date('Y-m-d', $ts),
                'open'   => (float) $opens[$i],
                'high'   => (float) $highs[$i],
                'low'    => (float) $lows[$i],
                'close'  => (float) $closes[$i],
                'volume' => (int) ($vols[$i] ?? 0),
            ];
        }

        return $rows;
    });
}

    // ── Yesterday's close ─────────────────────────────────────────────────

    public static function fetchYestClose(string $ticker): float
    {
        $cacheKey = "datafeed_yest_{$ticker}";

        return Cache::remember($cacheKey, static::HIST_TTL, function () use ($ticker) {
            $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$ticker}";
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($url, [
                    'interval' => '1d',
                    'range'    => '5d',
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Yahoo Finance history fetch failed for {$ticker}: HTTP {$response->status()}");
            }

            $data   = $response->json();
            $result = $data['chart']['result'][0] ?? null;

            if (!$result) {
                throw new \RuntimeException("No history data for {$ticker}");
            }

            $closes = $result['indicators']['quote'][0]['close'] ?? [];
            $closes = array_values(array_filter($closes, fn($v) => $v !== null));

            if (count($closes) < 2) {
                throw new \RuntimeException("Not enough daily bars for {$ticker}");
            }

            // Second-to-last = yesterday (last may be today partial)
            return (float) $closes[count($closes) - 2];
        });
    }

    // ── ATR (14-day True Range average) ──────────────────────────────────

    public static function fetchAtr(string $ticker, int $period = 14): float
    {
        $cacheKey = "datafeed_atr_{$ticker}_{$period}";

        return Cache::remember($cacheKey, static::HIST_TTL, function () use ($ticker, $period) {
            $url = "https://query2.finance.yahoo.com/v8/finance/chart/{$ticker}";
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get($url, [
                    'interval' => '1d',
                    'range'    => '30d',   // enough for 14-bar ATR
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Yahoo Finance ATR fetch failed for {$ticker}: HTTP {$response->status()}");
            }

            $data   = $response->json();
            $result = $data['chart']['result'][0] ?? null;

            if (!$result) {
                throw new \RuntimeException("No ATR data for {$ticker}");
            }

            $quote  = $result['indicators']['quote'][0] ?? [];
            $highs  = array_values(array_filter($quote['high']  ?? [], fn($v) => $v !== null));
            $lows   = array_values(array_filter($quote['low']   ?? [], fn($v) => $v !== null));
            $closes = array_values(array_filter($quote['close'] ?? [], fn($v) => $v !== null));

            $n = min(count($highs), count($lows), count($closes));

            if ($n < $period + 1) {
                throw new \RuntimeException("Not enough bars for ATR ({$n} bars, need " . ($period + 1) . ")");
            }

            // True Range = max(high-low, |high-prevClose|, |low-prevClose|)
            $trList = [];
            for ($i = 1; $i < $n; $i++) {
                $trList[] = max(
                    $highs[$i]  - $lows[$i],
                    abs($highs[$i]  - $closes[$i - 1]),
                    abs($lows[$i]   - $closes[$i - 1]),
                );
            }

            // Average of last $period true ranges
            $slice = array_slice($trList, -$period);
            $atr   = array_sum($slice) / count($slice);

            return round($atr, 4);
        });
    }

    // ── Cache busting (call after a tick to force fresh price next run) ───

    public static function bustPrice(string $ticker): void
    {
        Cache::forget("datafeed_price_{$ticker}");
    }
}

