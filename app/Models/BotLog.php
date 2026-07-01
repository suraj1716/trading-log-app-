<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotLog extends Model
{
    protected $fillable = [
        'event', 'price', 'atr', 'yest_close',
        'action', 'bias', 'momentum_score',
        'equity', 'drawdown_pct', 'cash', 'shares',
        'gate_passed', 'gate_reason', 'error_message',
        'interval_mode', 'duration_ms', 'logged_at', 'regime',
    'mom_direction',
    'trail_stop',
    'mom_peak_price',
    ];

    protected $casts = [
        'price'          => 'float',
        'atr'            => 'float',
        'yest_close'     => 'float',
        'momentum_score' => 'float',
        'equity'         => 'float',
        'drawdown_pct'   => 'float',
        'cash'           => 'float',
        'shares'         => 'integer',
        'gate_passed'    => 'boolean',
        'duration_ms'    => 'float',
        'logged_at'      => 'datetime',
         'trail_stop' => 'float',
    'mom_peak_price' => 'float',
    ];

    // ── Frontend row ──────────────────────────────────────────────────────

    public function toFrontendRow(): array
    {
        return [
            'id'            => $this->id,
            'event'         => $this->event,
            'date'          => $this->logged_at->format('d/m/y'),
            'time'          => $this->logged_at->format('H:i:s'),
            'price'         => $this->price,
            'atr'           => $this->atr,
            'yestClose'     => $this->yest_close,
            'action'        => $this->action,
            'bias'          => $this->bias,
            'momentumScore' => $this->momentum_score,
            'equity'        => $this->equity,
            'drawdownPct'   => $this->drawdown_pct,
            'cash'          => $this->cash,
            'shares'        => $this->shares,
            'gatePassed'    => $this->gate_passed,
            'gateReason'    => $this->gate_reason,
            'errorMessage'  => $this->error_message,
            'intervalMode'  => $this->interval_mode,
            'durationMs'    => $this->duration_ms,
        ];
    }
}

