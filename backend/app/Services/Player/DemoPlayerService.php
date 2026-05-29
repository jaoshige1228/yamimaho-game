<?php

namespace App\Services\Player;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DemoPlayerService
{
  public function __construct(
    private readonly UserCharacterService $characters = new UserCharacterService,
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
  }
}
