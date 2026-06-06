<?php

namespace Tests\Unit\Battle;

use App\Services\Battle\EnemySkillPicker;
use Tests\TestCase;

class EnemySkillPickerTest extends TestCase
{
    public function test_picks_flame_bite_for_inu_moe_at_low_roll(): void
    {
        $picker = new EnemySkillPicker(roll: static fn (): int => 0);
        $skill = $picker->pick('inu_moe');

        $this->assertSame('attack', $skill['skill_code']);
    }

    public function test_picks_flame_breath_for_inu_moe_at_mid_roll(): void
    {
        $picker = new EnemySkillPicker(roll: static fn (): int => 40);
        $skill = $picker->pick('inu_moe');

        $this->assertSame('flame_bite', $skill['skill_code']);
    }

    public function test_unknown_enemy_falls_back_to_attack(): void
    {
        $picker = new EnemySkillPicker;
        $skill = $picker->pick('bat');

        $this->assertSame('attack', $skill['skill_code']);
        $this->assertSame('punch', $skill['action_type']);
    }
}
