<?php

namespace Tests\Unit\Dungeon;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Dungeon\Exploration\DungeonExplorationEngine;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\UserCharacterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ExplorationStatCheckRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_success_rate_includes_hp_ratio_with_ceil(): void
    {
        $user = User::factory()->create();
        $characters = new UserCharacterService;
        $characters->ensureParty($user);

        /** @var UserCharacter $character */
        $character = $characters->partyInSlotOrder($user)->get('pc1');
        $master = $character->characterMaster;
        $growth = new LevelGrowthService;
        $maxHp = (int) $growth->statsForLevel($master, $character->level)['hp'];

        $character->hp = (int) floor($maxHp * 0.8);
        $character->str = 15;
        $character->save();

        $engine = new DungeonExplorationEngine;
        $method = new ReflectionMethod(DungeonExplorationEngine::class, 'successRateForSlot');
        $method->setAccessible(true);

        $rate = $method->invoke($engine, $user, 'pc1', 'str', 2);

        $this->assertSame(24, $rate);

        $character->hp = $maxHp;
        $character->save();

        $rateFullHp = $method->invoke($engine, $user->fresh(), 'pc1', 'str', 2);
        $this->assertSame(30, $rateFullHp);
    }

    public function test_success_rate_caps_at_100(): void
    {
        $user = User::factory()->create();
        $characters = new UserCharacterService;
        $characters->ensureParty($user);

        /** @var UserCharacter $character */
        $character = $characters->partyInSlotOrder($user)->get('pc1');
        $growth = new LevelGrowthService;
        $maxHp = (int) $growth->statsForLevel($character->characterMaster, $character->level)['hp'];

        $character->hp = $maxHp;
        $character->str = 50;
        $character->save();

        $engine = new DungeonExplorationEngine;
        $method = new ReflectionMethod(DungeonExplorationEngine::class, 'successRateForSlot');
        $method->setAccessible(true);

        $this->assertSame(100, $method->invoke($engine, $user, 'pc1', 'str', 3));
    }

    public function test_stat_check_label_for_spd(): void
    {
        $engine = new DungeonExplorationEngine;
        $method = new ReflectionMethod(DungeonExplorationEngine::class, 'statCheckLabel');
        $method->setAccessible(true);

        $this->assertSame('素早さ判定', $method->invoke($engine, 'spd'));
        $this->assertSame('筋力判定', $method->invoke($engine, 'str'));
    }
}
