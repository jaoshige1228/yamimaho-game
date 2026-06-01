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
        $this->assertSame('基本の杖', $rows[0]['name']);
        $this->assertSame('1', $rows[0]['mag_bonus']);
        $this->assertSame('学校から支給されたシンプルな杖。', $rows[0]['description']);
    }

    public function test_armor_masters_load_from_csv(): void
    {
        $rows = CsvMasterReader::read('armor_masters.csv');

        $this->assertNotEmpty($rows);
        $this->assertSame('robe_basic', $rows[0]['code']);
        $this->assertSame('基本のローブ', $rows[0]['name']);
        $this->assertSame('1', $rows[0]['def_bonus']);
        $this->assertStringContainsString('イケてる', $rows[0]['description']);
    }

    public function test_character_masters_include_default_equipment(): void
    {
        $rows = CsvMasterReader::read('character_masters.csv');

        foreach (['pc1', 'pc2', 'pc3', 'pc4'] as $code) {
            $row = collect($rows)->firstWhere('code', $code);
            $this->assertNotNull($row);
            $this->assertSame('staff_basic', $row['default_weapon']);
            $this->assertSame('robe_basic', $row['default_armor']);
        }
    }
}
