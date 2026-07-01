<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();

            // ── Status ──────────────────────────────────────────────────
            // 'stopped' | 'running' | 'paused'
            $table->string('status', 20)->default('stopped');

            // ── Schedule mode ───────────────────────────────────────────
            // 'test'  → every 1 minute
            // 'live'  → hourly at :35, market hours only (09:35–15:35 ET)
            $table->string('interval_mode', 10)->default('test');

            // ── Config (mirrors config.py) ──────────────────────────────
            $table->string('ticker', 10)->default('QBTS');
            $table->decimal('drawdown_limit_pct', 5, 2)->default(25.00);
            $table->boolean('test_mode')->default(true);  // bypass market hours

            // ── Runtime tracking ────────────────────────────────────────
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedInteger('total_ticks')->default(0);
            $table->unsignedInteger('total_trades')->default(0);
            $table->string('last_action', 120)->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};

