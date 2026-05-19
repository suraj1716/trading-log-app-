<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 20)->default('QBTS');
            $table->integer('initial_shares')->default(171);
            $table->decimal('initial_cash', 12, 2)->default(0);
            $table->decimal('trade_fee', 8, 2)->default(5);
            $table->json('atr_levels')->default('[0.5, 1.0, 1.5]');
            $table->json('base_fractions')->default('[0.3, 0.4, 0.3]');
            $table->decimal('max_drawdown', 5, 4)->default(0.25);
            $table->integer('momentum_lookback')->default(3);
            $table->integer('max_ticks')->default(50);
            $table->integer('current_shares')->default(171);
            $table->decimal('current_cash', 12, 2)->default(0);
            $table->decimal('avg_price', 10, 4)->default(0);
            $table->decimal('peak_equity', 12, 2)->default(0);
            $table->json('live_prices')->default('[]');
            $table->json('yest_closes')->default('[]');
            $table->json('live_atrs')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_sessions');
    }
};
