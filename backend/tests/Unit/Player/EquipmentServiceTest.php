<?php

namespace Tests\Unit\Player;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;
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

        $character = UserCharacter::query()->create([
            'user_id' => $user->id,
            'character_master_id' => $master->id,
            'level' => 1,
            'exp' => 0,
            'hp' => 100,
            'mp' => 45,
            'str' => 14,
            'mag' => 14,
            'def' => 9,
            'spd' => 12,
            'know' => 10,
            'spirit' => 10,
            'weapon_master_id' => $weapon->id,
            'armor_master_id' => $armor->id,
        ]);

        $stats = (new EquipmentService)->effectiveCombatStats($character);

        $this->assertSame(16, $stats['mag']);
        $this->assertSame(11, $stats['def']);
        $this->assertSame('staff_basic', $stats['weapon']['code']);
        $this->assertSame('robe_basic', $stats['armor']['code']);
    }
}
