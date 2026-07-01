<?php

namespace App\Http\Controllers;

use App\Models\BotLog;
use App\Models\BotSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    // ── Page ─────────────────────────────────────────────────────────────

    public function index(): Response
    {
        $bot  = BotSetting::instance();
        $logs = BotLog::orderByDesc('logged_at')
            ->limit(50)
            ->get()
            ->map(fn($l) => $l->toFrontendRow());

        return Inertia::render('BotDashboard', [
            'bot'  => $bot->toPayload(),
            'logs' => $logs,
        ]);
    }

    // ── Controls ──────────────────────────────────────────────────────────

    public function start(Request $request): JsonResponse
    {
        $bot = BotSetting::instance();

        if ($bot->isRunning()) {
            return response()->json(['error' => 'Bot is already running.'], 422);
        }

        $bot->update([
            'status'      => 'running',
            'last_error'  => null,
            'next_run_at' => $bot->computeNextRun(),
        ]);

        \App\Models\BotLog::create([
            'event'         => 'started',
            'interval_mode' => $bot->interval_mode,
        ]);

        return response()->json(['bot' => $bot->fresh()->toPayload()]);
    }

    public function pause(): JsonResponse
    {
        $bot = BotSetting::instance();

        if (!$bot->isRunning()) {
            return response()->json(['error' => 'Bot is not running.'], 422);
        }

        $bot->update(['status' => 'paused', 'next_run_at' => null]);

        \App\Models\BotLog::create([
            'event'         => 'paused',
            'interval_mode' => $bot->interval_mode,
        ]);

        return response()->json(['bot' => $bot->fresh()->toPayload()]);
    }

    public function stop(): JsonResponse
    {
        $bot = BotSetting::instance();

        $bot->update(['status' => 'stopped', 'next_run_at' => null]);

        \App\Models\BotLog::create([
            'event'         => 'stopped',
            'interval_mode' => $bot->interval_mode,
        ]);

        return response()->json(['bot' => $bot->fresh()->toPayload()]);
    }

    // ── Settings ──────────────────────────────────────────────────────────

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticker'            => 'required|string|max:10|alpha',
            'drawdown_limit_pct'=> 'required|numeric|min:1|max:100',
            'interval_mode'     => 'required|in:test,live',
            'test_mode'         => 'required|boolean',
        ]);

        $bot = BotSetting::instance();
        $bot->update($data);

        return response()->json(['bot' => $bot->fresh()->toPayload()]);
    }

    // ── Status polling ────────────────────────────────────────────────────

    public function status(): JsonResponse
    {
        $bot = BotSetting::instance();
        return response()->json(['bot' => $bot->toPayload()]);
    }

    // ── Logs (paginated) ──────────────────────────────────────────────────

    public function logs(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 200);
        $event   = $request->get('event');  // optional filter

        $query = BotLog::orderByDesc('logged_at');

        if ($event) {
            $query->where('event', $event);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'data'         => collect($paginated->items())->map(fn($l) => $l->toFrontendRow()),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }
}
