<?php

namespace App\Http\Controllers;

use App\Models\TradingSession;
use App\Models\TradeLog;
use App\Services\TradingEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TradingSessionController extends Controller
{
    public function index(): Response
    {

        $session = TradingSession::where('is_active', true)->latest()->first();

        if (!$session) {
            $session = TradingSession::create([
                'ticker' => 'QBTS',
                'initial_shares' => 171,
                'initial_cash' => 0,
                'trade_fee' => 5,
                'atr_levels' => [0.5, 1.0, 1.5],
                'base_fractions' => [0.3, 0.4, 0.3],
                'max_drawdown' => 0.25,
                'momentum_lookback' => 3,
                'max_ticks' => 50,
                'current_shares' => 171,
                'current_cash' => 0,
                'avg_price' => 19.07,
                'peak_equity' => 171 * 19.07,
                'live_prices' => [],
                'yest_closes' => [],
                'live_atrs' => [],
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

    public function runTick(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'price' => 'required|numeric|min:0.0001',
            'yest_close' => 'required|numeric|min:0.0001',
            'atr' => 'required|numeric|min:0.0001',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();

        $result = TradingEngine::run(
            $session->engine_state,
            (float) $request->price,
            (float) $request->yest_close,
            (float) $request->atr,
            $session->settings,
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        $tickNumber = $session->tradeLogs()->count() + 1;
        $log = $session->tradeLogs()->create(array_merge($result['logData'], [
            'tick_number' => $tickNumber,
            'trade_type' => 'auto',
        ]));

        $ns = $result['newState'];
        $session->update([
            'current_shares' => $ns['shares'],
            'current_cash' => $ns['cash'],
            'avg_price' => $ns['avgPrice'],
            'peak_equity' => $ns['peakEquity'],
            'live_prices' => $ns['liveP'],
            'yest_closes' => $ns['yestC'],
            'live_atrs' => $ns['liveA'],
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRow' => $log->toFrontendRow(),
        ]);
    }

    public function manualTrade(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'side' => 'required|in:BUY,SELL,HOLD',
            'price' => 'required|numeric|min:0.0001',
            'qty' => 'required|integer|min:1',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $lastLog = $session->tradeLogs()->latest('tick_number')->first();

        $prevClose = $lastLog?->price ?? (float) $request->price;
        $prevAtr = $lastLog?->atr ?? 1.0;

        $result = TradingEngine::run(
            $session->engine_state,
            (float) $request->price,
            $prevClose,
            $prevAtr,
            $session->settings,
            ['side' => $request->side, 'qty' => (int) $request->qty],
        );

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        $tickNumber = $session->tradeLogs()->count() + 1;
        $log = $session->tradeLogs()->create(array_merge($result['logData'], [
            'tick_number' => $tickNumber,
            'trade_type' => 'manual',
        ]));

        $ns = $result['newState'];
        $session->update([
            'current_shares' => $ns['shares'],
            'current_cash' => $ns['cash'],
            'avg_price' => $ns['avgPrice'],
            'peak_equity' => $ns['peakEquity'],
            'live_prices' => $ns['liveP'],
            'yest_closes' => $ns['yestC'],
            'live_atrs' => $ns['liveA'],
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRow' => $log->toFrontendRow(),
        ]);
    }

    public function deleteTick(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate(['row_number' => 'required|integer|min:2']);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $cutIdx = (int) $request->row_number - 2; // row 2 = index 0

        // Delete from cutIdx onward (tick_number >= cutIdx + 1)
        $session->tradeLogs()->where('tick_number', '>', $cutIdx)->delete();

        // Roll back state to last remaining log, or initial
        $lastLog = $session->tradeLogs()->latest('tick_number')->first();

        if (!$lastLog) {
            $session->update([
                'current_shares' => $session->initial_shares,
                'current_cash' => $session->initial_cash,
                'avg_price' => 19.07,
                'peak_equity' => $session->initial_shares * 19.07,
                'live_prices' => [],
                'yest_closes' => [],
                'live_atrs' => [],
            ]);
        } else {
            $keepCount = $lastLog->tick_number;
            $liveP = array_slice($session->live_prices, 0, $keepCount);
            $yestC = array_slice($session->yest_closes, 0, $keepCount);
            $liveA = array_slice($session->live_atrs, 0, $keepCount);
            $session->update([
                'current_shares' => $lastLog->shares,
                'current_cash' => $lastLog->cash,
                'avg_price' => $lastLog->avg_price,
                'peak_equity' => $lastLog->peak_equity,
                'live_prices' => $liveP,
                'yest_closes' => $yestC,
                'live_atrs' => $liveA,
            ]);
        }

        $logRows = $session->fresh()->tradeLogs()
            ->orderBy('tick_number')
            ->get()
            ->map(fn($l) => $l->toFrontendRow())
            ->values();

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRows' => $logRows,
        ]);
    }

    public function updateSettings(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'ticker' => 'required|string|max:20',
            'initial_shares' => 'required|integer|min:0',
            'initial_cash' => 'required|numeric|min:0',
            'trade_fee' => 'required|numeric|min:0',
            'atr_levels' => 'required|array|size:3',
            'atr_levels.*' => 'required|numeric|min:0',
            'base_fractions' => 'required|array|size:3',
            'base_fractions.*' => 'required|numeric|min:0|max:1',
            'max_drawdown' => 'required|numeric|min:0|max:1',
            'momentum_lookback' => 'required|integer|min:1',
            'max_ticks' => 'required|integer|min:10',
        ]);

        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $session->update($data);

        return response()->json(['session' => $this->sessionPayload($session->fresh())]);
    }

    public function reset(): \Illuminate\Http\JsonResponse
    {
        $session = TradingSession::where('is_active', true)->latest()->firstOrFail();
        $session->tradeLogs()->delete();
        $session->update([
            'current_shares' => $session->initial_shares,
            'current_cash' => $session->initial_cash,
            'avg_price' => 0,
            'peak_equity' => 0,
            'live_prices' => [],
            'yest_closes' => [],
            'live_atrs' => [],
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session->fresh()),
            'logRows' => [],
        ]);
    }

    private function sessionPayload(TradingSession $session): array
    {
        return [
            'id' => $session->id,
            'settings' => $session->settings,
            'state' => [
                'shares' => $session->current_shares,
                'cash' => $session->current_cash,
                'avgPrice' => $session->avg_price,
                'peakEquity' => $session->peak_equity,
                'tickCount' => count($session->live_prices ?? []),
                'maxTicks' => $session->max_ticks,
            ],
        ];
    }
}
