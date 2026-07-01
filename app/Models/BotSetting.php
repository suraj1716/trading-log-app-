<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotSetting extends Model
{
    protected $fillable = [
        'status', 'interval_mode', 'ticker', 'drawdown_limit_pct',
        'test_mode', 'last_run_at', 'next_run_at',
        'total_ticks', 'total_trades', 'last_action', 'last_error',
    ];

    protected $casts = [
        'test_mode'         => 'boolean',
        'drawdown_limit_pct'=> 'float',
        'total_ticks'       => 'integer',
        'total_trades'      => 'integer',
        'last_run_at'       => 'datetime',
        'next_run_at'       => 'datetime',
    ];

    // ── Convenience accessors ─────────────────────────────────────────────

    public function isRunning(): bool  { return $this->status === 'running'; }
    public function isPaused(): bool   { return $this->status === 'paused';  }
    public function isStopped(): bool  { return $this->status === 'stopped'; }

    // ── Singleton helper ──────────────────────────────────────────────────

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'status'             => 'stopped',
            'interval_mode'      => 'test',
            'ticker'             => 'QBTS',
            'drawdown_limit_pct' => 25.0,
            'test_mode'          => true,
        ]);
    }

    // ── Next run calculation ──────────────────────────────────────────────

    public function computeNextRun(): ?\Carbon\Carbon
    {
        if (!$this->isRunning()) return null;

        if ($this->interval_mode === 'test') {
            return now()->addMinute();
        }

        // Live mode: next :35 during market hours (ET)
        $et  = now()->setTimezone('America/New_York');
        $next = $et->copy()->startOfHour()->addMinutes(35);
        if ($next->lte($et)) $next->addHour();

        // Skip outside 09:35–15:35
        if ($next->hour < 9 || ($next->hour === 9 && $next->minute < 35)) {
            $next->setHour(9)->setMinute(35)->setSecond(0);
        }
        if ($next->hour >= 16) {
            $next->addDay()->setHour(9)->setMinute(35)->setSecond(0);
        }

        return $next->setTimezone('UTC');
    }

    // ── Frontend payload ──────────────────────────────────────────────────

    public function toPayload(): array
    {
        return [
            'status'           => $this->status,
            'intervalMode'     => $this->interval_mode,
            'ticker'           => $this->ticker,
            'drawdownLimitPct' => $this->drawdown_limit_pct,
            'testMode'         => $this->test_mode,
            'lastRunAt'        => $this->last_run_at?->toIso8601String(),
            'nextRunAt'        => $this->next_run_at?->toIso8601String(),
            'totalTicks'       => $this->total_ticks,
            'totalTrades'      => $this->total_trades,
            'lastAction'       => $this->last_action,
            'lastError'        => $this->last_error,
        ];
    }
}

