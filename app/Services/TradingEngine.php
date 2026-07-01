<?php

namespace App\Services;

class TradingEngine
{
    private static function r2(float $v): float { return round($v, 2); }
    private static function r4(float $v): float { return round($v, 4); }

    private static function linSlope(array $arr, int $idx, int $period): float
    {
        if ($idx < $period || empty($arr)) return 0;
        $y = array_slice($arr, max(0, $idx - $period), $period + 1);
        $n = count($y);
        if ($n < 2) return 0;
        $mx = ($n - 1) / 2;
        $my = array_sum($y) / $n;
        $num = 0; $den = 0;
        foreach ($y as $i => $v) {
            $num += ($i - $mx) * ($v - $my);
            $den += ($i - $mx) ** 2;
        }
        return $den == 0 ? 0 : $num / $den;
    }

    private static function rollMean(array $arr, int $w): array
    {
        if (empty($arr)) return [];
        $out = [];
        foreach ($arr as $i => $_) {
            $slice = array_slice($arr, max(0, $i - $w + 1), min($w, $i + 1));
            $out[] = array_sum($slice) / count($slice);
        }
        return $out;
    }

    private static function momentumSignal(array $prices, int $i, int $lb): int
    {
        if ($i < $lb) return 0;
        $w = array_slice($prices, $i - $lb, $lb + 1);
        $last = end($w);
        if ($last > $w[0]) return 1;
        if ($last < $w[0]) return -1;
        return 0;
    }

    private static function detectStrongMomentum(array $prices, array $atrs, int $idx, int $lb = 7): array
    {
        if (count($prices) < 2) return ['signal' => 0, 'score' => 0];
        $mom = $idx >= $lb ? $prices[$idx] - $prices[$idx - $lb] : 0;
        $maS = self::rollMean($prices, $lb);
        $atrS = self::rollMean(!empty($atrs) ? $atrs : [0], $lb);
        $maSlope = self::linSlope($maS, $idx, $lb);
        $atrSlope = self::linSlope($atrS, $idx, $lb);
        $score = 0;
        $score += $mom > 0 ? 1.5 : ($mom < 0 ? -1.5 : 0);
        $score += $maSlope > 0 ? 1.0 : ($maSlope < 0 ? -1.0 : 0);
        $score += $atrSlope > 0 ? 0.5 : ($atrSlope < 0 ? -0.5 : 0);
        return [
            'signal' => $score >= 2.5 ? 1 : ($score <= -2.5 ? -1 : 0),
            'score' => self::r4($score),
        ];
    }

    private static function predictBias(array $prices, array $yestC, array $atrs, int $i): string
    {
        if ($i < 3) return 'NONE';
        $mom = $prices[$i] - $prices[$i - 3];
        $atrSlope = $atrs[$i] - $atrs[$i - 3];
        $body = $prices[$i] - $yestC[$i];
        $s = ($mom > 0 ? 1 : -1) + ($atrSlope > 0 ? 1 : 0) + ($body > 0 ? 1 : -1);
        return $s >= 2 ? 'UP' : ($s <= -2 ? 'DOWN' : 'NONE');
    }

    private static function makeLadder(string $side, float $price, float $atr, float $cash, int $shares, array $cfg): array
    {
        $legs = [null, null, null];
        if ($side === 'BUY' && $cash > $price) {
            $usable = $cash;
            foreach ($cfg['atrLevels'] as $i => $m) {
                $p = self::r2($price - $m * $atr);
                $q = (int) floor(($usable / $p) * $cfg['baseFractions'][$i]);
                if ($q > 0) { $usable -= $q * $p; $legs[$i] = ['p' => $p, 'q' => $q]; }
            }
        } elseif ($side === 'SELL' && $shares > 0) {
            $rem = $shares;
            foreach ($cfg['atrLevels'] as $i => $m) {
                $p = self::r2($price + $m * $atr);
                $q = (int) floor($rem * $cfg['baseFractions'][$i]);
                if ($q > 0) { $rem -= $q; $legs[$i] = ['p' => $p, 'q' => $q]; }
            }
        }
        return $legs;
    }

    public static function run(array $rawState, float $price, float $yestClose, float $atr, array $cfg, ?array $manual = null): array
    {
        $tradeFee = $cfg['tradeFee'];
        $shares = $rawState['shares'];
        $cash = $rawState['cash'];
        $avgPrice = $rawState['avgPrice'];
        $peakEquity = $rawState['peakEquity'];
        $liveP = $rawState['liveP'];
        $yestC = $rawState['yestC'];
        $liveA = $rawState['liveA'];

        $liveP[] = $price;
        $yestC[] = $yestClose;
        $liveA[] = $atr;

        if (count($liveP) > $cfg['maxTicks']) {
            $liveP = array_slice($liveP, -$cfg['maxTicks']);
            $yestC = array_slice($yestC, -$cfg['maxTicks']);
            $liveA = array_slice($liveA, -$cfg['maxTicks']);
        }
        $idx = count($liveP) - 1;

        if (!$avgPrice) $avgPrice = $price;
        $equityNow = $cash + $shares * $price;
        if (!$peakEquity) $peakEquity = $equityNow;
        else $peakEquity = max($peakEquity, $equityNow);

        $buyLegs = [null, null, null];
        $sellLegs = [null, null, null];
        $action = 'HOLD';
        $holdReason = '';
        $realizedPL = 0;
        $momentumScore = 0;
        $bias = 'NONE';

        // Position underwater % from avg buy price
        $positionDD = ($avgPrice > 0 && $shares > 0)
            ? ($avgPrice - $price) / $avgPrice
            : 0;

        if ($manual) {
            $side = $manual['side'];
            $qty = $manual['qty'];
            if ($side === 'BUY') {
                if ($cash < $price * $qty + $tradeFee) return ['error' => 'Insufficient cash'];
                $cash -= $price * $qty + $tradeFee;
                $prev = $shares;
                $shares += $qty;
                $avgPrice = $shares > 0 ? ($avgPrice * $prev + $price * $qty) / $shares : $price;
                $action = "MANUAL BUY $qty";
            } elseif ($side === 'SELL') {
                if ($shares < $qty) return ['error' => 'Insufficient shares'];
                $realizedPL = self::r2(($price - $avgPrice) * $qty - $tradeFee);
                $cash += $price * $qty - $tradeFee;
                $shares -= $qty;
                $action = "MANUAL SELL $qty";
            } else {
                $action = 'MANUAL HOLD';
            }
        } else {
            $drawdown = $peakEquity ? ($peakEquity - $equityNow) / $peakEquity : 0;

            if ($drawdown < $cfg['maxDrawdown']) {
                $sig = self::momentumSignal($liveP, $idx, $cfg['momentumLookback']);
                ['signal' => $strongSig, 'score' => $score] = self::detectStrongMomentum($liveP, $liveA, $idx);
                $momentumScore = $score;
                $absScore = min(abs($score), 4);
                $ladderPct = 0.2 + 0.075 * $absScore;

                // ── DOWNTREND RESCUE: position underwater thresholds ──
                if ($sig === -1 && $shares > 0 && $positionDD >= 0.25 && $cash < $price) {
                    // 25%+ underwater + no cash → sell 30% to fund dip buying
                    $sellQty = (int) floor($shares * 0.30);
                    if ($sellQty > 0) {
                        $realizedPL = self::r2(($price - $avgPrice) * $sellQty - $tradeFee);
                        $cash += $sellQty * $price - $tradeFee;
                        $shares -= $sellQty;
                        $action = "RESCUE SELL $sellQty → buying dips";
                    }
                    // Use freed cash to ladder buy
                    $remCash = $cash * $ladderPct;
                    foreach ($cfg['atrLevels'] as $j => $m) {
                        $p = self::r2($price - $m * $atr);
                        $q = (int) floor(($remCash * $cfg['baseFractions'][$j]) / $p);
                        if ($q > 0) $buyLegs[$j] = ['p' => $p, 'q' => $q];
                    }

                } elseif ($sig === -1 && $positionDD >= 0.15 && $positionDD < 0.25 && $cash >= $price) {
                    // 15-25% underwater + has cash → ladder buy only, no sell
                    $remCash = $cash * $ladderPct;
                    foreach ($cfg['atrLevels'] as $j => $m) {
                        $p = self::r2($price - $m * $atr);
                        $q = (int) floor(($remCash * $cfg['baseFractions'][$j]) / $p);
                        if ($q > 0) $buyLegs[$j] = ['p' => $p, 'q' => $q];
                    }
                    $action = "DIP LADDER (15-25% down)";

                } elseif ($sig === -1 && $positionDD >= 0.40) {
                    // 40%+ underwater → stop all buying, just hold
                    $holdReason = 'position -40% underwater, holding';

                } elseif ($sig === -1 && $strongSig !== 1 && $cash > $price) {
                    // Normal downtrend buy (strong mom not opposing)
                    $mainCash = $cash * 0.5;
                    $qty = (int) floor($mainCash / $price);
                    $cost = $qty * $price;
                    if ($qty > 0) {
                        $cash -= $cost;
                        $prev = $shares;
                        $shares += $qty;
                        $avgPrice = $avgPrice ? ($avgPrice * $prev + $cost) / $shares : $price;
                        $action = abs($score) >= 3
                            ? "STRONG MOM BUY $qty = $" . self::r2($cost)
                            : "MOM BUY $qty = $" . self::r2($cost);
                    }
                    $remCash = $cash * $ladderPct;
                    foreach ($cfg['atrLevels'] as $j => $m) {
                        $p = self::r2($price - $m * $atr);
                        $q = (int) floor(($remCash * $cfg['baseFractions'][$j]) / $p);
                        if ($q > 0) $buyLegs[$j] = ['p' => $p, 'q' => $q];
                    }

                } elseif ($sig === 1 && $strongSig !== -1 && $shares > 0) {
                    // Normal uptrend sell (strong mom not opposing)
                    $mainPct = 0.5 - 0.075 * $absScore;
                    $qty = (int) floor($shares * $mainPct);
                    if ($qty > 0) {
                        $realizedPL = self::r2(($price - $avgPrice) * $qty - $tradeFee);
                        $cash += $qty * $price - $tradeFee;
                        $shares -= $qty;
                        $action = abs($score) >= 3
                            ? "STRONG MOM SELL $qty"
                            : "MOM SELL $qty";
                    }
                    $remShares = (int) floor($shares * $ladderPct);
                    foreach ($cfg['atrLevels'] as $j => $m) {
                        $p = self::r2($price + $m * $atr);
                        $q = min((int) floor($remShares * $cfg['baseFractions'][$j]), $remShares);
                        if ($q > 0) $sellLegs[$j] = ['p' => $p, 'q' => $q];
                    }

                } else {
                    if ($sig === 0) $holdReason = 'no momentum';
                    elseif ($sig === -1 && $cash <= $price) $holdReason = 'no cash';
                    elseif ($sig === 1 && $shares <= 0) $holdReason = 'no shares';
                }

            } else {
                $holdReason = 'drawdown limit';
            }

            if ($action === 'HOLD') {
                if ($holdReason) $action = "HOLD ($holdReason)";
                $bias = self::predictBias($liveP, $yestC, $liveA, $idx);
                if ($bias === 'UP') $sellLegs = self::makeLadder('SELL', $price, $atr, $cash, $shares, $cfg);
                elseif ($bias === 'DOWN') $buyLegs = self::makeLadder('BUY', $price, $atr, $cash, $shares, $cfg);
            }
        }

        $equity = $cash + $shares * $price;
        $finalDD = $peakEquity ? self::r2(($peakEquity - $equity) / $peakEquity * 100) : 0;
        $unrealizedPL = ($shares > 0 && $avgPrice) ? self::r2(($price - $avgPrice) * $shares) : 0;
        $peakEquity = max($peakEquity, $equity);

        return [
            'newState' => [
                'shares' => $shares,
                'cash' => self::r2($cash),
                'avgPrice' => self::r2($avgPrice),
                'peakEquity' => self::r2($peakEquity),
                'liveP' => $liveP,
                'yestC' => $yestC,
                'liveA' => $liveA,
            ],
            'logData' => [
                'price' => $price,
                'atr' => $atr,
                'yest_close' => $yestClose,
                'avg_price' => self::r2($avgPrice),
                'action' => $action,
                'bias' => $bias,
                'momentum_score' => $momentumScore,
            // add this new columnbto DB migration to log, then uncomment
                //'position_dd_pct' => self::r2($positionDD * 100),
                'buy_l1_price' => $buyLegs[0]['p'] ?? null,
                'buy_l1_qty' => $buyLegs[0]['q'] ?? null,
                'buy_l2_price' => $buyLegs[1]['p'] ?? null,
                'buy_l2_qty' => $buyLegs[1]['q'] ?? null,
                'buy_l3_price' => $buyLegs[2]['p'] ?? null,
                'buy_l3_qty' => $buyLegs[2]['q'] ?? null,
                'sell_l1_price' => $sellLegs[0]['p'] ?? null,
                'sell_l1_qty' => $sellLegs[0]['q'] ?? null,
                'sell_l2_price' => $sellLegs[1]['p'] ?? null,
                'sell_l2_qty' => $sellLegs[1]['q'] ?? null,
                'sell_l3_price' => $sellLegs[2]['p'] ?? null,
                'sell_l3_qty' => $sellLegs[2]['q'] ?? null,
                'cash' => self::r2($cash),
                'shares' => $shares,
                'equity' => self::r2($equity),
                'peak_equity' => self::r2($peakEquity),
                'drawdown_pct' => $finalDD,
                'realized_pl' => $realizedPL,
                'unrealized_pl' => $unrealizedPL,
            ],
        ];
    }
}
