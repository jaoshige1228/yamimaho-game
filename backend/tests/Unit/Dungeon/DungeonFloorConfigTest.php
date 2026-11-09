<?php

namespace Tests\Unit\Dungeon;

use App\Services\Dungeon\DungeonFloorConfig;
use Tests\TestCase;

class DungeonFloorConfigTest extends TestCase
{
    public function test_floor_two_mid_boss_step_is_20(): void
    {
        $this->assertSame(20, DungeonFloorConfig::midBossStep(2));
        $this->assertTrue(DungeonFloorConfig::isMidBossStep(2, 20));
        $this->assertFalse(DungeonFloorConfig::isMidBossStep(2, 19));
        $this->assertFalse(DungeonFloorConfig::isMidBossStep(1, 20));
    }

    public function test_boss_depth_is_the_same_on_floor_one_and_two(): void
    {
        $this->assertSame(31, DungeonFloorConfig::bossStep(1));
        $this->assertSame(31, DungeonFloorConfig::bossStep(2));
        $this->assertTrue(DungeonFloorConfig::isBossStep(2, 31));
        $this->assertFalse(DungeonFloorConfig::isBossStep(2, 41));
    }

    public function test_boss_enemies_per_floor(): void
    {
        $this->assertSame(['inu_moe'], DungeonFloorConfig::bossEnemyCodes(1));
        $this->assertSame(['mahou', 'dragon', 'mahou'], DungeonFloorConfig::bossEnemyCodes(2));
        $this->assertSame(['navi', 'zero', 'navi'], DungeonFloorConfig::bossEnemyCodes(3));
        $this->assertSame(31, DungeonFloorConfig::bossStep(3), '1・2 層と同じ深さ');
    }

    public function test_reward_items_are_upgraded_per_floor(): void
    {
        $this->assertSame('potion_hp_s', DungeonFloorConfig::rewardItemCode(1, 'potion_hp_s'));
        $this->assertSame('potion_mp_s', DungeonFloorConfig::rewardItemCode(1, 'potion_mp_s'));
        $this->assertSame('potion_hp_m', DungeonFloorConfig::rewardItemCode(2, 'potion_hp_s'));
        $this->assertSame('potion_mp_m', DungeonFloorConfig::rewardItemCode(2, 'potion_mp_s'));
        // 3 層は HP / MP 回復薬の「強」（大）
        $this->assertSame('potion_hp_l', DungeonFloorConfig::rewardItemCode(3, 'potion_hp_s'));
        $this->assertSame('potion_mp_l', DungeonFloorConfig::rewardItemCode(3, 'potion_mp_s'));
        // 差し替えの指定がないコード・層はそのまま
        $this->assertSame('world_tree_leaf', DungeonFloorConfig::rewardItemCode(2, 'world_tree_leaf'));
        $this->assertSame('world_tree_leaf', DungeonFloorConfig::rewardItemCode(3, 'world_tree_leaf'));
        $this->assertSame('potion_hp_s', DungeonFloorConfig::rewardItemCode(4, 'potion_hp_s'));
    }

    public function test_floor_two_mid_boss_event_and_enemies(): void
    {
        $this->assertSame('floor2_mid_boss', DungeonFloorConfig::midBossEventCode(2));
        $this->assertSame(['npc_kasu', 'npc_gusu'], DungeonFloorConfig::midBossEnemyCodes(2));
    }
}
