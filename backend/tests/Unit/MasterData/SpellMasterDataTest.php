<?php

namespace Tests\Unit\MasterData;

use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

class SpellMasterDataTest extends TestCase
{
    public function test_spell_masters_load_from_csv(): void
    {
        $provider = new MasterDataProvider;
        $spell = $provider->findSpell('pc3_fire_heavy');

        $this->assertSame('メテオフレイム', $spell['label']);
        $this->assertSame('damage', $spell['effect']);
        $this->assertArrayHasKey('coefficient', $spell);
        $this->assertGreaterThan(0, $spell['coefficient']);
    }

    public function test_character_spell_list_loads_from_csv(): void
    {
        $provider = new MasterDataProvider;

        $this->assertSame(
            ['pc1_water_single', 'pc1_heal_single', 'pc1_evasion_single_large', 'pc1_evasion_all_small'],
            $provider->spellIdsForCharacter('pc1'),
        );
    }
}
