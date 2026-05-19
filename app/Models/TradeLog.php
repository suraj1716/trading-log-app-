<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeLog extends Model
{
    protected $fillable = [
        'trading_session_id', 'tick_number', 'price', 'atr', 'yest_close',
        'avg_price', 'action', 'bias', 'momentum_score',
        'buy_l1_price', 'buy_l1_qty', 'buy_l2_price', 'buy_l2_qty', 'buy_l3_price', 'buy_l3_qty',
        'sell_l1_price', 'sell_l1_qty', 'sell_l2_price', 'sell_l2_qty', 'sell_l3_price', 'sell_l3_qty',
        'cash', 'shares', 'equity', 'peak_equity', 'drawdown_pct', 'realized_pl', 'unrealized_pl', 'trade_type',
    ];

    protected $casts = [
        'price' => 'float',
        'atr' => 'float',
        'yest_close' => 'float',
        'avg_price' => 'float',
        'momentum_score' => 'float',
        'buy_l1_price' => 'float',
        'buy_l2_price' => 'float',
        'buy_l3_price' => 'float',
        'sell_l1_price' => 'float',
        'sell_l2_price' => 'float',
        'sell_l3_price' => 'float',
        'cash' => 'float',
        'equity' => 'float',
        'peak_equity' => 'float',
        'drawdown_pct' => 'float',
        'realized_pl' => 'float',
        'unrealized_pl' => 'float',
    ];

    public function tradingSession(): BelongsTo
    {
        return $this->belongsTo(TradingSession::class);
    }

    public function toBuyLegs(): array
    {
        return [
            $this->buy_l1_qty ? ['p' => $this->buy_l1_price, 'q' => $this->buy_l1_qty] : null,
            $this->buy_l2_qty ? ['p' => $this->buy_l2_price, 'q' => $this->buy_l2_qty] : null,
            $this->buy_l3_qty ? ['p' => $this->buy_l3_price, 'q' => $this->buy_l3_qty] : null,
        ];
    }

    public function toSellLegs(): array
    {
        return [
            $this->sell_l1_qty ? ['p' => $this->sell_l1_price, 'q' => $this->sell_l1_qty] : null,
            $this->sell_l2_qty ? ['p' => $this->sell_l2_price, 'q' => $this->sell_l2_qty] : null,
            $this->sell_l3_qty ? ['p' => $this->sell_l3_price, 'q' => $this->sell_l3_qty] : null,
        ];
    }

    public function toFrontendRow(): array
    {
        $fmtLeg = fn($p, $q) => $q ? "$q @ $p = $" . round($q * $p, 2) : '';
        $fmtLegShort = fn($p, $q) => $q ? "$q @ $p" : '';

        return [
            'id' => $this->id,
            'tick' => $this->tick_number,
            'date' => $this->created_at->format('d/m/y'),
            'time' => $this->created_at->format('H:i'),
            'price' => $this->price,
            'atr' => $this->atr,
            'yestClose' => $this->yest_close,
            'avgPrice' => $this->avg_price,
            'action' => $this->action,
            'bias' => $this->bias,
            'momentumScore' => $this->momentum_score,
            'buyL1' => $fmtLeg($this->buy_l1_price, $this->buy_l1_qty),
            'buyL2' => $fmtLeg($this->buy_l2_price, $this->buy_l2_qty),
            'buyL3' => $fmtLeg($this->buy_l3_price, $this->buy_l3_qty),
            'sellL1' => $fmtLeg($this->sell_l1_price, $this->sell_l1_qty),
            'sellL2' => $fmtLeg($this->sell_l2_price, $this->sell_l2_qty),
            'sellL3' => $fmtLeg($this->sell_l3_price, $this->sell_l3_qty),
            'cash' => $this->cash,
            'shares' => $this->shares,
            'equity' => $this->equity,
            'peakEquity' => $this->peak_equity,
            'drawdown' => $this->drawdown_pct,
            'realizedPL' => $this->realized_pl,
            'unrealizedPL' => $this->unrealized_pl,
            'tradeType' => $this->trade_type,
        ];
    }
}
