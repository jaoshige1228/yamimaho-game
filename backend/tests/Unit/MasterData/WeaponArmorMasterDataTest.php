<?php

namespace Tests\Unit\MasterData;

use App\Services\MasterData\CsvMasterReader;
use Tests\TestCase;

class WeaponArmorMasterDataTest extends TestCase
{
    public function test_weapon_masters_load_from_csv(): void
    {
        $rows = CsvMasterReader::read('weapon_masters.csv');

        $this->assertNotEmpty($rows);
        $this->assertSame('staff_basic', $rows[0]['code']);
        $this->assertSame('初心者の杖', $rows[0]['name']);
        $this->assertSame('2', $rows[0]['mag_bonus']);
    }

    public function test_armor_masters_load_from_csv(): void
    {
        $rows = CsvMasterReader::read('armor_masters.csv');

        $this->assertNotEmpty($rows);
        $this->assertSame('robe_basic', $rows[0]['code']);
        $this->assertSame('布のローブ', $rows[0]['name']);
        $this->assertSame('2', $rows[0]['def_bonus']);
    }

    public function test_character_masters_include_default_equipment(): void
    {
        $rows = CsvMasterReader::read('character_masters.csv');
        $pc1 = collect($rows)->firstWhere('code', 'pc1');

        $this->assertNotNull($pc1);
        $this->assertSame('staff_basic', $pc1['default_weapon']);
        $this->assertSame('robe_basic', $pc1['default_armor']);
    }
}
