<?php

namespace App\Http\Controllers;

use App\Models\TradingSession;
use App\Models\TradeLog;
use App\Services\TradingEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TradingSessionController extends Controller
{
    // ── Page ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $session = TradingSession::where('is_active', true)->latest()->first();

        if (!$session) {
            $session = TradingSession::create([
                'ticker'               => 'QBTS',
                'initial_shares'       => 171,
                'initial_cash'         => 0,
                'trade_fee'            => 5.00,
                'min_fee_cover'        => 2.00,
                'min_move_atr_mult'    => 0.15,
                'momentum_lookback'    => 3,
                'strong_mom_lookback'  => 7,
                'min_qty'              => 5,
                'min_buy_dip_pct'      => 0.00,
                'atr_levels'           => [0.5, 1.0, 1.5],
                'base_fractions'       => [0.3, 0.4, 0.3],
                'max_drawdown'         => 0.25,
                'max_ticks'            => 50,
                'current_shares'       => 171,
                'current_cash'         => 0,
                'avg_price'            => 0,
                'peak_equity'          => 0,
                'live_prices'          => [],
                'yest_closes'          => [],
                'live_atrs'            => [],
                'is_active'            => true,
                'regime'               => 'ladder',
                'momentum_peak_price'  => null,
                'momentum_direction'   => null,
                'momentum_entry_price' => null,
            ]);
        }

        $logRows = $session->tradeLogs()
            ->orderBy('tick_number')
            ->get()
            ->map(fn($log) => $log->toFrontendRow())
            ->values();

        return Inertia::render('Dashboard', [
            'session' => $this->sessionPayload($session),
            'logRows' => $logRows,
        ]);
    }

    // ── Auto tick ────────────────────────────────────────────────────────────

    public function runTick(Request $request): \Illuminate\Http\JsonResponse
    {

        $request->validate([
            'price'      => 'required|numeric|min:0.0001',
            'yest_close' => 'required|numeric|min:0.0001',
            'atr'        => 'required|numeric|min:0.0001',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        Log::debug('[runTick] settings', [
            'maxTicks'          => $session->settings['maxTicks'] ?? 'MISSING',
            'live_prices_raw'   => $session->live_prices,
        ]);
        Log::debug('[runTick] session fetched', [
            'session_id'     => $session->id,
            'is_active'      => $session->is_active,
            'current_shares' => $session->current_shares,
            'current_cash'   => $session->current_cash,
            'avg_price'      => $session->avg_price,
            'peak_equity'    => $session->peak_equity,
            'live_prices_count' => count($session->live_prices ?? []),
        ]);
        $rawState = [
            'shares'     => $session->current_shares,
            'cash'       => $session->current_cash,
            'avgPrice'   => $session->avg_price,
            'peakEquity' => $session->peak_equity,
            'liveP'      => $session->live_prices ?? [],
            'yestC'      => $session->yest_closes ?? [],
            'liveA'      => $session->live_atrs   ?? [],
        ];

        $result = TradingEngine::run(
            $rawState,
            (float) $request->price,
            (float) $request->yest_close,
            (float) $request->atr,
            $session->settings,
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        $tickNumber = ($session->tradeLogs()->max('tick_number') ?? 0) + 1;
        $log = $session->tradeLogs()->create(array_merge($result['logData'], [
            'tick_number' => $tickNumber,
            'trade_type'  => 'auto',
        ]));

        $this->persistState($session, $result['newState']);
        Log::debug('[runTick] after persistState', [
            'new_shares' => $result['newState']['shares'],
            'new_cash'   => $result['newState']['cash'],
            'new_avg'    => $result['newState']['avgPrice'],
        ]);
        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRow'  => $log->toFrontendRow(),
        ]);
    }

    // ── Manual trade ─────────────────────────────────────────────────────────

    public function manualTrade(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'side'  => 'required|in:BUY,SELL,HOLD',
            'price' => 'required|numeric|min:0.0001',
            'qty'   => 'required|integer|min:1',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $lastLog = $session->tradeLogs()->latest('tick_number')->first();

        $prevClose = $lastLog?->price     ?? (float) $request->price;
        $prevAtr   = $lastLog?->atr       ?? 1.0;

        $rawState = [
            'shares'     => $session->current_shares,
            'cash'       => $session->current_cash,
            'avgPrice'   => $session->avg_price,
            'peakEquity' => $session->peak_equity,
            'liveP'      => $session->live_prices ?? [],
            'yestC'      => $session->yest_closes ?? [],
            'liveA'      => $session->live_atrs   ?? [],
        ];

        $result = TradingEngine::run(
            $rawState,
            (float) $request->price,
            $prevClose,
            $prevAtr,
            $session->settings,
            ['side' => $request->side, 'qty' => (int) $request->qty],
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }


        $tickNumber = ($session->tradeLogs()->max('tick_number') ?? 0) + 1;

        $log = $session->tradeLogs()->create(array_merge($result['logData'], [
            'tick_number' => $tickNumber,
            'trade_type'  => 'manual',
        ]));

        $this->persistState($session, $result['newState']);

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRow'  => $log->toFrontendRow(),
        ]);
    }

    // ── Rollback ─────────────────────────────────────────────────────────────

    public function deleteTick(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['row_number' => 'required|integer|min:1']);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();

        Log::debug('[deleteTick] start', [
            'row_number'     => $request->row_number,
            'session_id'     => $session->id,
            'current_shares' => $session->current_shares,
            'current_cash'   => $session->current_cash,
        ]);

        $targetExists = $session->tradeLogs()->where('tick_number', (int) $request->row_number)->exists();
        if (!$targetExists && (int) $request->row_number !== 1) {
            return response()->json(['error' => 'Tick not found, refresh and retry.'], 422);
        }

     DB::transaction(function () use ($session, $request) {
    TradeLog::where('trading_session_id', $session->id)
        ->where('tick_number', '>=', (int) $request->row_number)
        ->delete();

    $lastLog = TradeLog::where('trading_session_id', $session->id)
        ->orderBy('id', 'desc')
        ->first();

    $remainingIds = TradeLog::where('trading_session_id', $session->id)
        ->orderBy('id')
        ->pluck('id')
        ->toArray();

    Log::debug('[deleteTick] remaining ids after delete', ['ids' => $remainingIds]);
    Log::debug('[deleteTick] lastLog picked', [
        'id'          => $lastLog?->id,
        'tick_number' => $lastLog?->tick_number,
        'shares'      => $lastLog?->shares,
        'cash'        => $lastLog?->cash,
    ]);

    if (!$lastLog) {
        $session->update([
            'current_shares' => $session->initial_shares,
            'current_cash'   => $session->initial_cash,
            'avg_price'      => 0,
            'peak_equity'    => 0,
            'live_prices'    => [],
            'yest_closes'    => [],
            'live_atrs'      => [],
        ]);
        return;
    }

    $allLogs = TradeLog::where('trading_session_id', $session->id)
        ->orderBy('id')
        ->get();

    $session->update([
        'current_shares' => $lastLog->shares,
        'current_cash'   => $lastLog->cash,
        'avg_price'      => $lastLog->avg_price,
        'peak_equity'    => $lastLog->peak_equity,
        'live_prices'    => $allLogs->pluck('price')->map(fn($v) => (float)$v)->values()->all(),
        'yest_closes'    => $allLogs->pluck('yest_close')->map(fn($v) => (float)$v)->values()->all(),
        'live_atrs'      => $allLogs->pluck('atr')->map(fn($v) => (float)$v)->values()->all(),
    ]);
});

        $session->refresh();

        $logRows = $session->tradeLogs()
            ->orderBy('tick_number')
            ->get()
            ->map(fn($l) => $l->toFrontendRow())
            ->values();

        Log::debug('[deleteTick] done', [
            'remaining_rows'    => $logRows->count(),
            'session_shares'    => $session->current_shares,
            'session_cash'      => $session->current_cash,
            'live_prices_count' => count($session->live_prices ?? []),
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session),
            'logRows' => $logRows,
        ]);
    }
    // ── Settings ─────────────────────────────────────────────────────────────

    public function updateSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'ticker'               => 'required|string|max:20',
            'initial_shares'       => 'required|integer|min:0',
            'initial_cash'         => 'required|numeric|min:0',
            'trade_fee'            => 'required|numeric|min:0',
            'min_fee_cover'        => 'required|numeric|min:0',
            'min_move_atr_mult'    => 'required|numeric|min:0|max:1',
            'momentum_lookback'    => 'required|integer|min:1',
            'strong_mom_lookback'  => 'required|integer|min:1',
            'min_qty'              => 'required|integer|min:1',
            'min_buy_dip_pct'      => 'required|numeric|min:0',
            'atr_levels'           => 'required|array|size:3',
            'atr_levels.*'         => 'required|numeric|min:0',
            'base_fractions'       => 'required|array|size:3',
            'base_fractions.*'     => 'required|numeric|min:0|max:1',
            'max_drawdown'         => 'required|numeric|min:0|max:1',
            'max_ticks'            => 'required|integer|min:10',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $session->update($data);

        return response()->json(['session' => $this->sessionPayload($session->fresh())]);
    }

    // ── Reset ─────────────────────────────────────────────────────────────────

    public function reset(): \Illuminate\Http\JsonResponse
    {
        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $session->tradeLogs()->delete();
        $session->update([
            'current_shares' => $session->initial_shares,
            'current_cash'   => $session->initial_cash,
            'avg_price'      => 0,
            'peak_equity'    => 0,
            'live_prices'    => [],
            'yest_closes'    => [],
            'live_atrs'      => [],
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRows' => [],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function persistState(TradingSession $session, array $ns): void
    {
        $session->update([
            'current_shares' => $ns['shares'],
            'current_cash'   => $ns['cash'],
            'avg_price'      => $ns['avgPrice'],
            'peak_equity'    => $ns['peakEquity'],
            'live_prices'    => $ns['liveP'],
            'yest_closes'    => $ns['yestC'],
            'live_atrs'      => $ns['liveA'],
        ]);
    }

    private function sessionPayload(TradingSession $session): array
    {
        return [
            'id'       => $session->id,
            'settings' => $session->settings,
            'state'    => [
                'shares'     => $session->current_shares,
                'cash'       => $session->current_cash,
                'avgPrice'   => $session->avg_price,
                'peakEquity' => $session->peak_equity,
                'tickCount'  => count($session->live_prices ?? []),
                'maxTicks'   => $session->max_ticks,
            ],
        ];
    }
}
