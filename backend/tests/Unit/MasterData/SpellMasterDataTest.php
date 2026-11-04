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

        // 何も覚えていないときは、最初から使える魔法だけ
        $this->assertSame(
            ['pc1_heal_water', 'pc1_water_blade', 'pc1_water_veil'],
            $provider->spellIdsForCharacter('pc1'),
        );
    }

    public function test_new_spells_follow_the_listed_order_once_learned(): void
    {
        $provider = new MasterDataProvider;
        $expected = [
            'pc1' => ['pc1_heal_water', 'pc1_holy_water', 'pc1_water_blade', 'pc1_water_veil', 'pc1_water_veil_all'],
            'pc2' => ['pc2_vine_whip', 'pc2_vine_bind', 'pc2_burst_tree', 'pc2_world_tree', 'pc2_world_tree_breath'],
            'pc3' => ['pc3_fire_shot', 'pc3_fire_burst', 'pc3_fire_wave', 'pc3_black_hole', 'pc3_ragaon_beat'],
            'pc4' => ['pc4_stone_shot', 'pc4_rock_fall', 'pc4_earth_armor', 'pc4_earth_wall', 'pc4_shield_magic'],
        ];

        foreach ($expected as $character => $spellIds) {
            $learnable = $provider->spellIdsLearnedByDefeating($character, ['inu_moe']);
            $this->assertCount(2, $learnable, $character);
            $this->assertSame($spellIds, $provider->spellIdsForCharacter($character, $learnable), $character);
            $this->assertSame([], $provider->spellIdsLearnedByDefeating($character, ['bat']), $character);
        }
    }

    public function test_new_spells_have_the_requested_mp_and_effects(): void
    {
        $provider = new MasterDataProvider;

        $holy = $provider->findSpell('pc1_holy_water');
        $this->assertSame([12, 'heal_mag', 3.5], [$holy['mp_cost'], $holy['effect'], $holy['coefficient']]);

        $veilAll = $provider->findSpell('pc1_water_veil_all');
        $this->assertSame([18, 'ally_all', 'evasion_set_small'], [$veilAll['mp_cost'], $veilAll['target_type'], $veilAll['buff']]);
        $this->assertSame(30, config('battle.buffs.evasion_set_small.value'));

        $burstTree = $provider->findSpell('pc2_burst_tree');
        $this->assertSame([20, 'enemy_all', 'def_down_small'], [$burstTree['mp_cost'], $burstTree['target_type'], $burstTree['buff']]);

        $breath = $provider->findSpell('pc2_world_tree_breath');
        $this->assertSame([24, 'revive_chance', 100], [$breath['mp_cost'], $breath['effect'], $breath['power']]);

        $blackHole = $provider->findSpell('pc3_black_hole');
        $this->assertSame(
            [30, 'enemy_all', 'dark', 3.0, 50],
            [$blackHole['mp_cost'], $blackHole['target_type'], $blackHole['element'], $blackHole['coefficient'], $blackHole['power']],
        );

        $ragaon = $provider->findSpell('pc3_ragaon_beat');
        $this->assertSame([20, 'enemy_single', 'def_down_huge'], [$ragaon['mp_cost'], $ragaon['target_type'], $ragaon['buff']]);

        $rockFall = $provider->findSpell('pc4_rock_fall');
        $this->assertSame([12, 'earth', 3.0], [$rockFall['mp_cost'], $rockFall['element'], $rockFall['coefficient']]);

        $earthWall = $provider->findSpell('pc4_earth_wall');
        $this->assertSame([20, 'ally_all', 'def_up_small'], [$earthWall['mp_cost'], $earthWall['target_type'], $earthWall['buff']]);
    }
}
