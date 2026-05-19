<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TradingSessionController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });
Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Route::get('/dashboard', function () {
//     return Inertia::render('Dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Trading System Routes
|--------------------------------------------------------------------------
*/

Route::get('/dashboard', [TradingSessionController::class, 'index'])->name('dashboard');

Route::prefix('api/trading')->name('trading.')->group(function () {
    Route::post('/tick',        [TradingSessionController::class, 'runTick'])->name('tick');
    Route::post('/manual',      [TradingSessionController::class, 'manualTrade'])->name('manual');
    Route::post('/delete-tick', [TradingSessionController::class, 'deleteTick'])->name('delete-tick');
    Route::put('/settings',     [TradingSessionController::class, 'updateSettings'])->name('settings');
    Route::post('/reset',       [TradingSessionController::class, 'reset'])->name('reset');
});



require __DIR__.'/auth.php';
