<?php

namespace App\Services\Player;

use App\Models\User;
use App\Services\Dungeon\DungeonProgressService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoPlayerService
{
  public function __construct(
    private readonly UserCharacterService $characters = new UserCharacterService,
    private readonly DungeonProgressService $dungeonProgress = new DungeonProgressService,
    private readonly PartyGoldService $gold = new PartyGoldService,
    private readonly ItemInventoryService $items = new ItemInventoryService,
    private readonly FamiliarService $familiars = new FamiliarService,
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

  /** Cookie のトークンの形（64 桁の 16 進）。形が違うものは検索せず、無効として扱う */
  public static function isValidToken(mixed $token): bool
  {
    return is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
  }

  public static function hashToken(string $token): string
  {
    return hash('sha256', $token);
  }

  /**
   * Cookie のトークンに紐づくユーザー（そのブラウザのセーブ）。なければ null。
   */
  public function findByToken(mixed $token): ?User
  {
    if (! self::isValidToken($token)) {
      return null;
    }

    return User::query()->where('player_token_hash', self::hashToken($token))->first();
  }

  /**
   * 新しいブラウザのセーブ（ゲスト）を作る。キャラ・初期装備・初期アイテムまで用意する。
   * 生のトークンは Cookie に入れて返すだけで、DB にはハッシュしか残さない。
   *
   * @return array{user: User, token: string}
   */
  public function createGuest(): array
  {
    $token = bin2hex(random_bytes(32));

    $user = new User([
      'name' => '勇者',
      'email' => 'guest-'.Str::uuid().'@yamimaho.local',
      'password' => Str::random(40),
    ]);
    $user->player_token_hash = self::hashToken($token);
    $user->save();

    $this->characters->ensureParty($user);

    return ['user' => $user, 'token' => $token];
  }

  public function resetProgress(User $user): void
  {
    $this->characters->resetParty($user);
    $this->dungeonProgress->resetAll($user);
    $this->gold->resetGold($user);
    $this->items->resetItems($user);
    $this->familiars->reset($user);
  }
}
