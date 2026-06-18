<?php

namespace Tests\Unit\Battle;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Battle\BattleRewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattleRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_victory_rewards_use_enemy_exp_total(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $character = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $beforeExp = (int) $character->exp;

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'イルミ',
                    'alive' => true,
                    'hp' => 50,
                    'mp' => 20,
                    'user_character_id' => $character->id,
                ],
                'enemy_1' => [
                    'id' => 'enemy_1',
                    'side' => 'enemy',
                    'alive' => false,
                    'exp_reward' => 20,
                ],
                'enemy_2' => [
                    'id' => 'enemy_2',
                    'side' => 'enemy',
                    'alive' => false,
                    'exp_reward' => 30,
                ],
            ],
            'meta' => [
                'exp_earned' => 50,
            ],
        ];

        $service = new BattleRewardService;
        $events = $service->applyVictoryRewards($state, $user);

        $expEvent = collect($events)->firstWhere('type', 'exp_gained');
        $this->assertNotNull($expEvent);
        $this->assertSame(50, $expEvent['amount']);

        $character->refresh();
        $this->assertSame($beforeExp + 50, (int) $character->exp);
    }

    public function test_defeated_allies_do_not_receive_exp(): void
    {
        $user = User::query()->where('email', config('game.demo_user_email'))->firstOrFail();

        $alive = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc1'))
            ->firstOrFail();
        $dead = UserCharacter::query()
            ->where('user_id', $user->id)
            ->whereHas('characterMaster', fn ($q) => $q->where('code', 'pc2'))
            ->firstOrFail();
        $deadBefore = (int) $dead->exp;

        $state = [
            'units' => [
                'pc1' => [
                    'id' => 'pc1',
                    'side' => 'ally',
                    'name' => 'イルミ',
                    'alive' => true,
                    'hp' => 10,
                    'mp' => 10,
                    'user_character_id' => $alive->id,
                ],
                'pc2' => [
                    'id' => 'pc2',
                    'side' => 'ally',
                    'name' => 'ヨウ',
                    'alive' => false,
                    'hp' => 0,
                    'mp' => 0,
                    'user_character_id' => $dead->id,
                ],
            ],
            'meta' => [
                'exp_earned' => 20,
            ],
        ];

        $service = new BattleRewardService;
        $events = $service->applyVictoryRewards($state, $user);

        $this->assertCount(1, array_filter($events, fn (array $e): bool => ($e['type'] ?? '') === 'exp_gained'));

        $dead->refresh();
        $this->assertSame($deadBefore, (int) $dead->exp);
    }
}
