<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BattleController;
use App\Http\Controllers\DungeonController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ResetController;
use Illuminate\Support\Facades\Route;

Route::middleware('demo.player')->group(function (): void {
    Route::get('/player/party', [PlayerController::class, 'party']);
    Route::get('/player/stats', [PlayerController::class, 'stats']);
    Route::get('/dungeon', [DungeonController::class, 'show']);
    Route::post('/dungeon/enter', [DungeonController::class, 'enter']);
    Route::post('/dungeon/advance', [DungeonController::class, 'advance']);

    Route::post('/battles/demo', [BattleController::class, 'demo']);
    Route::post('/battles/demo-kappa2', [BattleController::class, 'demoKappa2']);
    Route::get('/battles/{id}', [BattleController::class, 'show']);
    Route::post('/battles/{id}/actions', [BattleController::class, 'action']);
    Route::post('/reset', [ResetController::class, 'reset']);
});

Route::middleware(['demo.player', 'non.production'])->prefix('admins')->group(function (): void {
    Route::get('/status', [AdminController::class, 'status']);
    Route::get('/party', [AdminController::class, 'party']);
    Route::post('/grant-exp', [AdminController::class, 'grantExp']);
});
