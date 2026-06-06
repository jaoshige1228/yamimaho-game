<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BattleController;
use App\Http\Controllers\DungeonController;
use App\Http\Controllers\DungeonExplorationController;
use App\Http\Controllers\DungeonHealController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\ResetController;
use App\Http\Controllers\ShopController;
use Illuminate\Support\Facades\Route;

Route::middleware('demo.player')->group(function (): void {
    Route::get('/player/party', [PlayerController::class, 'party']);
    Route::get('/player/stats', [PlayerController::class, 'stats']);
    Route::get('/player/navigation', [PlayerController::class, 'navigation']);
    Route::get('/player/equipment', [PlayerController::class, 'equipment']);
    Route::post('/player/equipment/equip', [PlayerController::class, 'equip']);
    Route::get('/shop/equipment', [ShopController::class, 'equipment']);
    Route::post('/shop/equipment/buy', [ShopController::class, 'buyEquipment']);
    Route::get('/dungeon', [DungeonController::class, 'show']);
    Route::post('/dungeon/enter', [DungeonController::class, 'enter']);
    Route::post('/dungeon/advance', [DungeonController::class, 'advance']);
    Route::post('/dungeon/retreat', [DungeonController::class, 'retreat']);
    Route::get('/dungeon/heal/options', [DungeonHealController::class, 'options']);
    Route::post('/dungeon/heal', [DungeonHealController::class, 'heal']);
    Route::post('/dungeon/exploration/continue', [DungeonExplorationController::class, 'continue']);
    Route::post('/dungeon/exploration/choose', [DungeonExplorationController::class, 'choose']);

    Route::post('/battles/demo', [BattleController::class, 'demo']);
    Route::post('/battles/demo-boss', [BattleController::class, 'demoBoss']);
    Route::get('/battles/{id}', [BattleController::class, 'show']);
    Route::post('/battles/{id}/actions', [BattleController::class, 'action']);
    Route::post('/reset', [ResetController::class, 'reset']);
});

Route::middleware(['demo.player', 'non.production'])->prefix('admins')->group(function (): void {
    Route::get('/status', [AdminController::class, 'status']);
    Route::get('/party', [AdminController::class, 'party']);
    Route::post('/grant-exp', [AdminController::class, 'grantExp']);
    Route::post('/dungeon-settings', [AdminController::class, 'dungeonSettings']);
});
