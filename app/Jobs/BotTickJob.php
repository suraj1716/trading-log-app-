<?php

namespace App\Jobs;

use App\Models\BotLog;
use App\Models\BotSetting;
use App\Models\TradingSession;
use App\Services\DataFeed;
use App\Services\TradingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * BotTickJob — ports bot.py::run_check()
 *
 * Dispatched by the Laravel scheduler every minute.
 * Exits immediately if bot is stopped/paused or
 * market is closed (live mode).
 *
 * Flow (mirrors bot.py):
 *   1. Check bot status gate
 *   2. Check market hours gate (live mode only)
 *   3. Fetch price / yest_close / ATR
 *   4. Load trading session
 *   5. DRY RUN — calculate tick without saving
 *   6. Drawdown gate check
 *   7. Execute (save state + trade log) if gates pass
 *   8. Write bot log entry
 *   9. Update bot_settings counters
 */
class BotTickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 60;


    // uncomment when every minute live price if fetched
    public function handle(): void
    {
        $start = microtime(true);
        $bot   = BotSetting::instance();

        // ── Gate 1: Status ────────────────────────────────────────────────
        if (!$bot->isRunning()) {
            // Nothing to do — scheduler fires every minute but we only act when running
            return;
        }

        // ── Gate 2: Interval mode ─────────────────────────────────────────
        // In test mode: always proceed.
        // In live mode: only run at :35 past each hour, Mon–Fri, 09:35–15:35 ET.
        if ($bot->interval_mode === 'live' && !$this->isMarketWindow()) {
            BotLog::create([
                'event'         => 'skipped',
                'gate_reason'   => 'outside market window',
                'interval_mode' => $bot->interval_mode,
                'duration_ms'   => $this->ms($start),
            ]);
            return;
        }

        // ── Step 3: Fetch market data ─────────────────────────────────────
        try {
            $data = DataFeed::fetchAll($bot->ticker);

        } catch (\Throwable $e) {
            $this->logError($bot, 'Data fetch failed: ' . $e->getMessage(), $start);
            return;
        }

        $price     = $data['price'];
        $yestClose = $data['yest_close'];
        $atr       = $data['atr'];

        // ── Step 4: Load trading session ──────────────────────────────────
        $session = TradingSession::where('is_active', true)->latest()->first();
        if (!$session) {
            $this->logError($bot, 'No active trading session found.', $start);
            return;
        }

        // ── Step 5: DRY RUN ───────────────────────────────────────────────
        // Calculate result without saving state — mirrors bot.py dry run
        try {
            $result = TradingEngine::run(
                $session->engine_state,
                $price,
                $yestClose,
                $atr,
                $session->settings,
            );
        } catch (\Throwable $e) {
            $this->logError($bot, 'Engine calculation failed: ' . $e->getMessage(), $start);
            return;
        }

        if (isset($result['error'])) {
            $this->logError($bot, 'Engine error: ' . $result['error'], $start);
            return;
        }

        $logData = $result['logData'];
        $newState = $result['newState'];
        $action   = $logData['action'];
        $isHold   = str_contains($action, 'HOLD');

        // ── Gate 3: Drawdown gate ─────────────────────────────────────────
        // Bot-level gate: block ALL trades when drawdown >= drawdown_limit_pct
        // (engine has its own internal 25% limit — this is the configurable bot gate)
        $drawdown = $logData['drawdown_pct'];
        if (!$isHold && $drawdown >= $bot->drawdown_limit_pct) {
            // Save tick to state (price history still useful for momentum)
            $this->saveState($session, $newState, $logData);

            BotLog::create([
                'event'         => 'blocked',
                'price'         => $price,
                'atr'           => $atr,
                'yest_close'    => $yestClose,
                'action'        => $action,
                'bias'          => $logData['bias'],
                'momentum_score'=> $logData['momentum_score'],
                'equity'        => $logData['equity'],
                'drawdown_pct'  => $drawdown,
                'cash'          => $logData['cash'],
                'shares'        => $logData['shares'],
                'gate_passed'   => false,
                'gate_reason'   => "Drawdown {$drawdown}% ≥ limit {$bot->drawdown_limit_pct}%",
                'interval_mode' => $bot->interval_mode,
                'duration_ms'   => $this->ms($start),
            ]);

            $bot->update([
                'last_run_at' => now(),
                'next_run_at' => $bot->computeNextRun(),
                'total_ticks' => $bot->total_ticks + 1,
                'last_action' => "BLOCKED: {$action}",
                'last_error'  => null,
            ]);
            return;
        }

        // ── Step 7: All gates passed ──────────────────────────────────────
        // Save state + write trade log
        $tickNumber = $session->tradeLogs()->count() + 1;

        $session->tradeLogs()->create(array_merge($logData, [
            'tick_number' => $tickNumber,
            'trade_type'  => 'bot',
        ]));

        $this->saveState($session, $newState, $logData);

        // Bust price cache so next tick gets a fresh fetch
        DataFeed::bustPrice($bot->ticker);

        // ── Step 8: Bot log ───────────────────────────────────────────────
        $isTrade = !$isHold;

        BotLog::create([
            'event'          => $isHold ? 'tick' : 'tick',
            'price'          => $price,
            'atr'            => $atr,
            'yest_close'     => $yestClose,
            'action'         => $action,
            'bias'           => $logData['bias'],
            'momentum_score' => $logData['momentum_score'],
            'equity'         => $logData['equity'],
            'drawdown_pct'   => $drawdown,
            'cash'           => $logData['cash'],
            'shares'         => $logData['shares'],
            'gate_passed'    => true,
            'gate_reason'    => null,
            'interval_mode'  => $bot->interval_mode,
            'duration_ms'    => $this->ms($start),
        ]);

        // ── Step 9: Update bot counters ───────────────────────────────────
        $bot->update([
            'last_run_at'   => now(),
            'next_run_at'   => $bot->computeNextRun(),
            'total_ticks'   => $bot->total_ticks + 1,
            'total_trades'  => $isTrade ? $bot->total_trades + 1 : $bot->total_trades,
            'last_action'   => $action,
            'last_error'    => null,
        ]);
    }


//testing series with date range of the ticker: QBTS
    // public function handle(): void
    // {
    //     $start = microtime(true);

    //     $bot = BotSetting::instance();

    //     if (!$bot->isRunning()) {
    //         return;
    //     }

    //     try {

    //         $history = DataFeed::fetchDailyHistory(
    //             $bot->ticker,

    //             '2025-12-31',
    //             '2026-05-21'

    //         );

    //         // Initial engine state
    //         $state = [
    //             'shares'      => $bot->shares ?? 0,
    //             'cash'        => $bot->cash ?? 10000,
    //             'avgPrice'    => $bot->avg_price ?? 0,
    //             'peakEquity'  => $bot->peak_equity ?? 0,
    //             'liveP'       => [],
    //             'yestC'       => [],
    //             'liveA'       => [],
    //         ];

    //         foreach ($history as $i => $bar) {

    //             $price = (float) $bar['close'];

    //             // Yesterday close
    //             $yestClose = $i > 0
    //                 ? (float) $history[$i - 1]['close']
    //                 : $price;

    //             // ATR
    //             $atr = $this->calculateAtr($history, $i);

    //             // Run engine
    //             $result = TradingEngine::run(
    //                 $state,
    //                 $price,
    //                 $yestClose,
    //                 $atr,
    //                 [
    //                     'tradeFee'          => $bot->trade_fee,
    //                     'maxTicks'          => 500,
    //                     'maxDrawdown'       => $bot->max_drawdown,
    //                     'momentumLookback' => $bot->momentum_lookback,

    //                     'atrLevels' => [
    //                         $bot->buy_l1_atr,
    //                         $bot->buy_l2_atr,
    //                         $bot->buy_l3_atr,
    //                     ],

    //                     'baseFractions' => [
    //                         $bot->buy_l1_pct / 100,
    //                         $bot->buy_l2_pct / 100,
    //                         $bot->buy_l3_pct / 100,
    //                     ],
    //                 ]
    //             );

    //             // Update rolling state
    //             $state = $result['newState'];

    //             // Save log
    //             BotLog::create([
    //                 'event'     => 'tick',
    //                 'date'      => $bar['date'],
    //                 'price'     => $price,
    //                 'atr'       => $atr,
    //                 'action'    => $result['logData']['action'],
    //                 'equity'    => $result['logData']['equity'],
    //                 'cash'      => $result['logData']['cash'],
    //                 'shares'    => $result['logData']['shares'],
    //                 'bias'      => $result['logData']['bias'],
    //                 'drawdown_pct' => $result['logData']['drawdown_pct'],
    //                 'regime' => $logData['regime'] ?? null,
    //                 'mom_direction' => $logData['mom_direction'] ?? null,
    //                 'trail_stop' => $logData['trail_stop'] ?? null,
    //                 'mom_peak_price' => $logData['mom_peak_price'] ?? null,
    //             ]);
    //         }
    //     } catch (\Throwable $e) {

    //         BotLog::create([
    //             'event' => 'error',
    //             'notes' => $e->getMessage(),
    //         ]);
    //     }
    // }


    private function calculateAtr(array $history, int $idx, int $period = 14): float
    {
        if ($idx < $period) {
            return 0;
        }

        $trs = [];

        for ($i = $idx - $period + 1; $i <= $idx; $i++) {

            $high = $history[$i]['high'];
            $low  = $history[$i]['low'];

            $prevClose = $history[$i - 1]['close'];

            $trs[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        return round(array_sum($trs) / count($trs), 4);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function saveState(TradingSession $session, array $ns, array $logData): void
    {
        $session->update([
            'current_shares' => $ns['shares'],
            'current_cash'   => $ns['cash'],
            'avg_price'      => $ns['avgPrice'],
            'peak_equity'    => $ns['peakEquity'],
            'live_prices'    => $ns['liveP'],
            'yest_closes'    => $ns['yestC'],
            'live_atrs'      => $ns['liveA'],
            'regime' => $ns['regime'],
            'momentum_peak_price' => $ns['momentumPeakPrice'],
            'momentum_direction' => $ns['momentumDirection'],
            'momentum_entry_price' => $ns['momentumEntryPrice'],
        ]);
    }

    private function logError(BotSetting $bot, string $msg, float $start): void
    {
        BotLog::create([
            'event'         => 'error',
            'error_message' => $msg,
            'interval_mode' => $bot->interval_mode,
            'duration_ms'   => $this->ms($start),
        ]);

        $bot->update([
            'last_run_at' => now(),
            'next_run_at' => $bot->computeNextRun(),
            'last_error'  => $msg,
        ]);
    }

    private function ms(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Live mode: only fire at :35 past each hour, Mon–Fri, 09:35–15:35 ET.
     * Mirrors bot.py market hours check.
     */
    private function isMarketWindow(): bool
    {
        $et  = now()->setTimezone('America/New_York');

        // Weekends
        if ($et->isWeekend()) return false;

        // Market hours: 09:35–15:59
        if ($et->hour < 9 || $et->hour >= 16) return false;
        if ($et->hour === 9 && $et->minute < 35) return false;

        // Only at :35 past each hour (±2 min tolerance for scheduler drift)
        $minDiff = abs($et->minute - 35);
        return $minDiff <= 2;
    }
}
