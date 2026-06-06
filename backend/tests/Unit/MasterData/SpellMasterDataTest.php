<?php

namespace Tests\Unit\MasterData;

use App\Services\MasterData\MasterDataProvider;
use Tests\TestCase;

class SpellMasterDataTest extends TestCase
{
    public function test_spell_masters_load_from_csv(): void
    {
        $provider = new MasterDataProvider;
        $spell = $provider->findSpell('pc3_fire_burst');

        $this->assertSame('爆破', $spell['label']);
        $this->assertSame('damage', $spell['effect']);
        $this->assertArrayHasKey('coefficient', $spell);
        $this->assertGreaterThan(0, $spell['coefficient']);
    }

    public function test_character_spell_list_loads_from_csv(): void
    {
        $provider = new MasterDataProvider;

        $this->assertSame(
            ['pc1_heal_water', 'pc1_water_blade', 'pc1_water_veil'],
            $provider->spellIdsForCharacter('pc1'),
        );
    }
}
