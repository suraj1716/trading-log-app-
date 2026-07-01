<?php

use App\Http\Controllers\BotController;
use Illuminate\Support\Facades\Route;

// ── Bot Dashboard (Inertia page) ──────────────────────────────────────────
Route::get('/bot', [BotController::class, 'index'])->name('bot.index');

// ── Bot Controls ──────────────────────────────────────────────────────────
Route::prefix('bot')->name('bot.')->group(function () {
    Route::post('start',    [BotController::class, 'start'])->name('start');
    Route::post('pause',    [BotController::class, 'pause'])->name('pause');
    Route::post('stop',     [BotController::class, 'stop'])->name('stop');
    Route::put('settings',  [BotController::class, 'updateSettings'])->name('settings');
    Route::get('status',    [BotController::class, 'status'])->name('status');
    Route::get('logs',      [BotController::class, 'logs'])->name('logs');
});
