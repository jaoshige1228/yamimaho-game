<?php

namespace App\Services\Player;

use App\Models\User;
use App\Services\Dungeon\DungeonProgressService;
use Illuminate\Support\Facades\Hash;

class DemoPlayerService
{
  public function __construct(
    private readonly UserCharacterService $characters = new UserCharacterService,
    private readonly DungeonProgressService $dungeonProgress = new DungeonProgressService,
    private readonly PartyGoldService $gold = new PartyGoldService,
    private readonly ItemInventoryService $items = new ItemInventoryService,
  ) {}

  public function resolveOrCreateUser(): User
  {
    $user = User::query()->firstOrCreate(
      ['email' => config('game.demo_user_email')],
      [
        'name' => '勇者',
        'password' => Hash::make('demo-not-for-production'),
      ],
    );

    $this->characters->ensureParty($user);

    return $user;
  }

  public function resetProgress(User $user): void
  {
    $this->characters->resetParty($user);
    $this->dungeonProgress->resetAll($user);
    $this->gold->resetGold($user);
    $this->items->resetItems($user);
  }
}
