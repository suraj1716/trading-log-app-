<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_sessions', function (Blueprint $table) {
            // These 5 columns were missing — engine was getting null for all of them,
            // making fee gate, noise filter, dip filter, and min qty all non-functional.
            $table->decimal('min_fee_cover',       5, 2)->default(2.00)  ->after('trade_fee');
            $table->decimal('min_move_atr_mult',   6, 4)->default(0.15)  ->after('min_fee_cover');
            $table->integer('strong_mom_lookback')       ->default(7)     ->after('min_move_atr_mult');
            $table->integer('min_qty')                   ->default(5)     ->after('strong_mom_lookback');
            $table->decimal('min_buy_dip_pct',     5, 2)->default(0.00)  ->after('min_qty');
        });

        // Backfill any existing sessions with sensible defaults
        DB::table('trading_sessions')->update([
            'min_fee_cover'      => 2.00,
            'min_move_atr_mult'  => 0.15,
            'strong_mom_lookback'=> 7,
            'min_qty'            => 5,
            'min_buy_dip_pct'    => 0.00,
        ]);
    }

    public function down(): void
    {
        Schema::table('trading_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'min_fee_cover',
                'min_move_atr_mult',
                'strong_mom_lookback',
                'min_qty',
                'min_buy_dip_pct',
            ]);
        });
    }
};
