<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_session_id')->constrained()->cascadeOnDelete();
            $table->integer('tick_number');
            $table->decimal('price', 10, 4);
            $table->decimal('atr', 10, 4);
            $table->decimal('yest_close', 10, 4);
            $table->decimal('avg_price', 10, 4);
            $table->string('action', 100);
            $table->string('bias', 10)->default('NONE');
            $table->decimal('momentum_score', 8, 4)->default(0);
            // Buy ladder legs (price, qty for each level)
            $table->decimal('buy_l1_price', 10, 4)->nullable();
            $table->integer('buy_l1_qty')->nullable();
            $table->decimal('buy_l2_price', 10, 4)->nullable();
            $table->integer('buy_l2_qty')->nullable();
            $table->decimal('buy_l3_price', 10, 4)->nullable();
            $table->integer('buy_l3_qty')->nullable();
            // Sell ladder legs
            $table->decimal('sell_l1_price', 10, 4)->nullable();
            $table->integer('sell_l1_qty')->nullable();
            $table->decimal('sell_l2_price', 10, 4)->nullable();
            $table->integer('sell_l2_qty')->nullable();
            $table->decimal('sell_l3_price', 10, 4)->nullable();
            $table->integer('sell_l3_qty')->nullable();
            // Position snapshot
            $table->decimal('cash', 12, 2);
            $table->integer('shares');
            $table->decimal('equity', 12, 2);
            $table->decimal('peak_equity', 12, 2);
            $table->decimal('drawdown_pct', 8, 4)->default(0);
            $table->decimal('realized_pl', 10, 2)->default(0);
            $table->decimal('unrealized_pl', 10, 2)->default(0);
            $table->enum('trade_type', ['auto', 'manual'])->default('auto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_logs');
    }
};
