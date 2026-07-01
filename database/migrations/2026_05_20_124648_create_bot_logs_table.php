
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->id();

            // ── What triggered this log entry ───────────────────────────
            // 'tick' | 'skipped' | 'blocked' | 'error' | 'started' | 'stopped' | 'paused'
            $table->string('event', 20)->default('tick');

            // ── Market data (null on error/skip) ────────────────────────
            $table->decimal('price', 12, 4)->nullable();
            $table->decimal('atr', 12, 4)->nullable();
            $table->decimal('yest_close', 12, 4)->nullable();

            // ── Engine result ────────────────────────────────────────────
            $table->string('action', 120)->nullable();
            $table->string('bias', 10)->nullable();
            $table->decimal('momentum_score', 8, 4)->nullable();
            $table->decimal('equity', 12, 4)->nullable();
            $table->decimal('drawdown_pct', 8, 4)->nullable();
            $table->decimal('cash', 12, 4)->nullable();
            $table->integer('shares')->nullable();

            // ── Gate result ──────────────────────────────────────────────
            $table->boolean('gate_passed')->nullable();
            $table->string('gate_reason', 255)->nullable();   // why blocked/skipped

            // ── Error detail ─────────────────────────────────────────────
            $table->text('error_message')->nullable();

            // ── Schedule meta ─────────────────────────────────────────────
            $table->string('interval_mode', 10)->nullable();  // 'test' | 'live'
            $table->decimal('duration_ms', 10, 2)->nullable(); // how long the tick took

            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();

            $table->index('event');
            $table->index('logged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};


