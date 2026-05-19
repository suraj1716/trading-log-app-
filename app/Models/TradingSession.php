<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingSession extends Model
{
    protected $fillable = [
        'ticker', 'initial_shares', 'initial_cash', 'trade_fee',
        'atr_levels', 'base_fractions', 'max_drawdown', 'momentum_lookback',
        'max_ticks', 'current_shares', 'current_cash', 'avg_price',
        'peak_equity', 'live_prices', 'yest_closes', 'live_atrs', 'is_active',
    ];

    protected $casts = [
        'atr_levels' => 'array',
        'base_fractions' => 'array',
        'live_prices' => 'array',
        'yest_closes' => 'array',
        'live_atrs' => 'array',
        'is_active' => 'boolean',
        'max_drawdown' => 'float',
        'initial_cash' => 'float',
        'current_cash' => 'float',
        'avg_price' => 'float',
        'peak_equity' => 'float',
    ];

    public function tradeLogs(): HasMany
    {
        return $this->hasMany(TradeLog::class)->orderBy('tick_number');
    }

    public function getEquityAttribute(): float
    {
        $lastLog = $this->tradeLogs()->latest('tick_number')->first();
        return $lastLog ? (float) $lastLog->equity : (float) ($this->current_cash + $this->current_shares * $this->avg_price);
    }

    public function getEngineStateAttribute(): array
    {
        return [
            'shares' => $this->current_shares,
            'cash' => $this->current_cash,
            'avgPrice' => $this->avg_price,
            'peakEquity' => $this->peak_equity,
            'liveP' => $this->live_prices ?? [],
            'yestC' => $this->yest_closes ?? [],
            'liveA' => $this->live_atrs ?? [],
            'stopLossFlags' => [],
            'cooldownDays' => 0,
            'lockedCash' => 0,
        ];
    }

    public function getSettingsAttribute(): array
    {
        return [
            'ticker' => $this->ticker,
            'initialShares' => $this->initial_shares,
            'initialCash' => $this->initial_cash,
            'tradeFee' => $this->trade_fee,
            'atrLevels' => $this->atr_levels,
            'baseFractions' => $this->base_fractions,
            'maxDrawdown' => $this->max_drawdown,
            'momentumLookback' => $this->momentum_lookback,
            'maxTicks' => $this->max_ticks,
        ];
    }
}
