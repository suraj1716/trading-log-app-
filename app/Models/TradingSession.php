<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingSession extends Model
{
    protected $fillable = [
        'ticker', 'initial_shares', 'initial_cash',
        // Algo config — all must be fillable so updateSettings() can save them
        'trade_fee',
        'min_fee_cover',        // ← was missing
        'min_move_atr_mult',    // ← was missing
        'strong_mom_lookback',  // ← was missing
        'min_qty',              // ← was missing
        'min_buy_dip_pct',      // ← was missing
        'atr_levels', 'base_fractions', 'max_drawdown',
        'momentum_lookback', 'max_ticks',
        // Runtime state
        'current_shares', 'current_cash', 'avg_price',
        'peak_equity', 'live_prices', 'yest_closes', 'live_atrs', 'is_active',
        'regime',
    'momentum_peak_price',
    'momentum_direction',
    'momentum_entry_price',
    'last_action',
'last_signal_level',
'last_trade_price',
'position_entry_price',
    'current_session',
    'session_buys',
    ];

    protected $casts = [
        'atr_levels'         => 'array',
        'base_fractions'     => 'array',
        'live_prices'        => 'array',
        'yest_closes'        => 'array',
        'live_atrs'          => 'array',
        'is_active'          => 'boolean',
        'max_drawdown'       => 'float',
        'initial_cash'       => 'float',
        'current_cash'       => 'float',
        'avg_price'          => 'float',
        'peak_equity'        => 'float',
        'trade_fee'          => 'float',
        'min_fee_cover'      => 'float',
        'min_move_atr_mult'  => 'float',
        'strong_mom_lookback'=> 'integer',
        'min_qty'            => 'integer',
        'min_buy_dip_pct'    => 'float',
          'momentum_peak_price' => 'float',
    'momentum_entry_price' => 'float',
    'last_trade_price'     => 'float',
'position_entry_price' => 'float',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function tradeLogs(): HasMany
    {
        return $this->hasMany(TradeLog::class)->orderBy('tick_number');
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    public function getEquityAttribute(): float
    {
        $lastLog = $this->tradeLogs()->latest('tick_number')->first();
        return $lastLog
            ? (float) $lastLog->equity
            : (float) ($this->current_cash + $this->current_shares * $this->avg_price);
    }

    /**
     * Raw engine state — passed as first arg to TradingEngine::run()
     */
    public function getEngineStateAttribute(): array
    {
        return [
            'shares'     => (int)   $this->current_shares,
            'cash'       => (float) $this->current_cash,
            'avgPrice'   => (float) $this->avg_price,
            'peakEquity' => (float) $this->peak_equity,
            'liveP'      => $this->live_prices  ?? [],
            'yestC'      => $this->yest_closes  ?? [],
            'liveA'      => $this->live_atrs    ?? [],
        ];
    }

    /**
     * Algo config — passed as fourth arg to TradingEngine::run()
     * Every key the engine reads from $cfg must be present here.
     * Previously missing 5 keys caused fee gate, noise filter, dip filter,
     * min qty, and strong-momentum lookback to all silently fail.
     */
    public function getSettingsAttribute(): array
    {
        return [
            // Identity
            'ticker'            => $this->ticker,
            'initialShares'     => (int)   $this->initial_shares,
            'initialCash'       => (float) $this->initial_cash,

            // Fee config
            'tradeFee'          => (float) ($this->trade_fee         ?? 5.0),
            'minFeeCover'       => (float) ($this->min_fee_cover     ?? 2.0),   // gain must be ≥ tradeFee × minFeeCover

            // Signal filters
            'minMoveAtrMult'    => (float) ($this->min_move_atr_mult    ?? 0.15), // move must be ≥ 15% of ATR
            'momentumLookback'  => (int)   ($this->momentum_lookback    ?? 3),
            'strongMomLookback' => (int)   ($this->strong_mom_lookback  ?? 7),

            // Trade size guards
            'minQty'            => (int)   ($this->min_qty           ?? 5),      // never trade < 5 shares
            'minBuyDipPct'      => (float) ($this->min_buy_dip_pct   ?? 0.0),   // price must be < avg × (1 + pct/100)

            // Ladder
            'atrLevels'         => $this->atr_levels     ?? [0.5, 1.0, 1.5],
            'baseFractions'     => $this->base_fractions ?? [0.3, 0.4, 0.3],

            // Risk
            'maxDrawdown'       => (float) ($this->max_drawdown ?? 0.25),
            'maxTicks'          => (int)   ($this->max_ticks    ?? 50),
        ];
    }
}
