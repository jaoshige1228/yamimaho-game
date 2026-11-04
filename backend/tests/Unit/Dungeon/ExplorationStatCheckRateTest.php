<?php

namespace Tests\Unit\Dungeon;

use App\Models\DungeonEventNode;
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

    public function test_success_rate_caps_at_95(): void
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

        $this->assertSame(95, $method->invoke($engine, $user, 'pc1', 'str', 3));
    }

    public function test_floor_two_halves_stat_multiplier_with_ceil(): void
    {
        $user = User::factory()->create();
        $characters = new UserCharacterService;
        $characters->ensureParty($user);

        /** @var UserCharacter $character */
        $character = $characters->partyInSlotOrder($user)->get('pc1');
        $growth = new LevelGrowthService;
        $maxHp = (int) $growth->statsForLevel($character->characterMaster, $character->level)['hp'];

        $character->hp = $maxHp;
        $character->str = 15;
        $character->save();

        $engine = new DungeonExplorationEngine;
        $rate = new ReflectionMethod(DungeonExplorationEngine::class, 'successRateForSlot');
        $rate->setAccessible(true);
        $multiplier = new ReflectionMethod(DungeonExplorationEngine::class, 'effectiveStatMultiplier');
        $multiplier->setAccessible(true);

        $node = new DungeonEventNode(['stat_multiplier' => 4]);
        $floorOne = $rate->invoke($engine, $user, 'pc1', 'str', $multiplier->invoke($engine, $node, 2, 1));
        $floorTwo = $rate->invoke($engine, $user->fresh(), 'pc1', 'str', $multiplier->invoke($engine, $node, 2, 2));

        $this->assertSame(60, $floorOne);
        $this->assertSame(30, $floorTwo);
    }

    public function test_overridden_multiplier_is_used_as_is_and_rounded_up(): void
    {
        $user = User::factory()->create();
        $characters = new UserCharacterService;
        $characters->ensureParty($user);

        /** @var UserCharacter $character */
        $character = $characters->partyInSlotOrder($user)->get('pc1');
        $growth = new LevelGrowthService;
        $maxHp = (int) $growth->statsForLevel($character->characterMaster, $character->level)['hp'];

        $character->hp = $maxHp;
        $character->str = 15;
        $character->save();

        $engine = new DungeonExplorationEngine;
        $rate = new ReflectionMethod(DungeonExplorationEngine::class, 'successRateForSlot');
        $rate->setAccessible(true);
        $multiplier = new ReflectionMethod(DungeonExplorationEngine::class, 'effectiveStatMultiplier');
        $multiplier->setAccessible(true);

        // 層ごとの上書き（×2.3）は層補正（2 層は半分）をかけずにそのまま使う
        $node = new DungeonEventNode(['stat_multiplier' => 3]);
        $node->stat_multiplier = 2.3;
        $node->statMultiplierOverridden = true;

        $this->assertSame(2.3, $multiplier->invoke($engine, $node, 2, 2));
        // 15 × 2.3 = 34.5 → 35
        $this->assertSame(35, $rate->invoke($engine, $user, 'pc1', 'str', 2.3));

        // 端数がちょうど整数になる組み合わせでも、浮動小数点の誤差で 1 増えない（10 × 2.3 = 23）
        $character->str = 10;
        $character->save();
        $this->assertSame(23, $rate->invoke($engine, $user->fresh(), 'pc1', 'str', 2.3));
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
