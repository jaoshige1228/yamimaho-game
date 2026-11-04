<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserCharacterLearnedSpell;
use App\Services\Battle\BattleEngine;
use App\Services\Battle\BattleOrchestrator;
use App\Services\Battle\EnemySkillPicker;
use App\Services\Player\PartyGoldService;
use App\Services\Player\SpellLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 敵が逃げて戦闘が終わったときは勝利として扱うが、経験値・ゴールドは入らない。
 */
class EnemyFleeVictoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
        $this->postJson('/api/battles/demo')->assertOk();
    }

    public function test_mimic_fleeing_ends_the_battle_as_a_victory_without_exp_or_gold(): void
    {
        $user = $this->demoUser();
        $goldBefore = app(PartyGoldService::class)->getGold($user);
        $expBefore = UserCharacter::query()->where('user_id', $user->id)->pluck('exp', 'id')->all();

        // ミミックは素早さが高く先手を取る。「逃げる」を引く出目に固定する
        $orchestrator = new BattleOrchestrator(
            engine: new BattleEngine(enemySkillPicker: new EnemySkillPicker(roll: static fn (): int => 60)),
        );
        $result = $orchestrator->createBattleForUser($user, [[
            'slot' => 'enemy_1',
            'master_code' => 'mimic',
            'floor' => 2,
            'name' => 'ミミック',
            'sprite' => '2_mimic',
        ]], ['source' => 'test', 'can_flee' => false]);

        $this->assertSame('victory', $result['state']['status']);
        $types = array_column($result['events'], 'type');
        $this->assertContains('enemy_fled', $types);
        $this->assertContains('battle_end', $types);
        $this->assertNotContains('exp_gained', $types);
        $this->assertNotContains('level_up', $types);
        $this->assertNotContains('gold_reward_applied', $types);

        $this->assertSame($goldBefore, app(PartyGoldService::class)->getGold($user->fresh()));
        $this->assertSame(
            $expBefore,
            UserCharacter::query()->where('user_id', $user->id)->pluck('exp', 'id')->all(),
        );
    }

    public function test_a_fled_enemy_does_not_count_as_defeated_for_learning_spells(): void
    {
        $state = ['units' => [
            'enemy_1' => ['side' => 'enemy', 'master_code' => 'inu_moe', 'fled' => true, 'alive' => false],
        ]];

        $this->assertSame([], app(SpellLearningService::class)->learnAfterVictory($state, $this->demoUser()));
        $this->assertSame(0, UserCharacterLearnedSpell::query()->count());
        $this->assertSame(0, (int) UserCharacter::query()->sum('max_mp_bonus'));
    }

    private function demoUser(): User
    {
        return User::query()->where('email', config('game.demo_user_email'))->firstOrFail();
    }
}
