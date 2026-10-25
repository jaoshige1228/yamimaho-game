<?php

namespace Tests\Unit\Player;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\EquipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MasterDataSeeder::class);
    }

    public function test_effective_combat_stats_adds_weapon_and_armor_bonuses(): void
    {
        $user = User::factory()->create();
        $master = CharacterMaster::query()->where('code', 'pc1')->firstOrFail();
        $weapon = WeaponMaster::query()->where('code', 'staff_basic')->firstOrFail();
        $armor = ArmorMaster::query()->where('code', 'robe_basic')->firstOrFail();
        $stats = (new LevelGrowthService)->statsForLevel($master, 1);

        $character = UserCharacter::query()->create([
            'user_id' => $user->id,
            'character_master_id' => $master->id,
            'level' => 1,
            'exp' => 0,
            'hp' => $stats['hp'],
            'mp' => $stats['mp'],
            'str' => $stats['str'],
            'mag' => $stats['mag'],
            'def' => $stats['def'],
            'spd' => $stats['spd'],
            'know' => $stats['know'],
            'spirit' => $stats['spirit'],
            'weapon_master_id' => $weapon->id,
            'armor_master_id' => $armor->id,
        ]);

        $combat = (new EquipmentService)->effectiveCombatStats($character);

        $this->assertSame($stats['mag'] + $weapon->mag_bonus, $combat['mag']);
        $this->assertSame($stats['def'] + $armor->def_bonus, $combat['def']);
        $this->assertSame('staff_basic', $combat['weapon']['code']);
        $this->assertSame('robe_basic', $combat['armor']['code']);
    }
}
