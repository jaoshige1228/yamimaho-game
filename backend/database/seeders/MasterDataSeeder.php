<?php

namespace Database\Seeders;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\EnemyMaster;
use App\Models\LevelMaster;
use App\Models\SpellMaster;
use App\Models\WeaponMaster;
use App\Services\MasterData\CsvMasterReader;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CsvMasterReader::read('weapon_masters.csv') as $row) {
            WeaponMaster::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'mag_bonus' => (int) $row['mag_bonus'],
                ],
            );
        }

        foreach (CsvMasterReader::read('armor_masters.csv') as $row) {
            ArmorMaster::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'def_bonus' => (int) $row['def_bonus'],
                ],
            );
        }

        foreach (CsvMasterReader::read('character_masters.csv') as $row) {
            CharacterMaster::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'sprite' => $row['sprite'],
                    'hp' => (int) $row['hp'],
                    'mp' => (int) $row['mp'],
                    'str' => (int) $row['str'],
                    'mag' => (int) $row['mag'],
                    'def' => (int) $row['def'],
                    'spd' => (int) $row['spd'],
                    'know' => (int) $row['know'],
                    'spirit' => (int) $row['spirit'],
                    'default_weapon_code' => ($row['default_weapon'] ?? '') !== '' ? $row['default_weapon'] : null,
                    'default_armor_code' => ($row['default_armor'] ?? '') !== '' ? $row['default_armor'] : null,
                ],
            );
        }

        foreach (CsvMasterReader::read('enemy_masters.csv') as $row) {
            EnemyMaster::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'sprite' => $row['sprite'],
                    'hp' => (int) $row['hp'],
                    'mp' => (int) $row['mp'],
                    'str' => (int) $row['str'],
                    'mag' => (int) $row['mag'],
                    'def' => (int) $row['def'],
                    'spd' => (int) $row['spd'],
                    'know' => (int) $row['know'],
                    'spirit' => (int) $row['spirit'],
                    'exp_reward' => (int) $row['exp_reward'],
                ],
            );
        }

        foreach (CsvMasterReader::read('spell_masters.csv') as $row) {
            SpellMaster::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'label' => $row['label'],
                    'description' => $row['description'] ?? null,
                    'mp_cost' => (int) $row['mp_cost'],
                    'target_type' => $row['target_type'],
                    'effect' => $row['effect'],
                    'element' => ($row['element'] ?? '') !== '' ? $row['element'] : null,
                    'coefficient' => ($row['coefficient'] ?? '') !== '' ? (float) $row['coefficient'] : null,
                    'power' => ($row['power'] ?? '') !== '' ? (int) $row['power'] : null,
                    'buff_key' => ($row['buff_key'] ?? '') !== '' ? $row['buff_key'] : null,
                ],
            );
        }

        $characters = CharacterMaster::query()->get()->keyBy('code');
        $spells = SpellMaster::query()->get()->keyBy('code');

        /** @var array<string, list<array<string, string>>> $grouped */
        $grouped = [];
        foreach (CsvMasterReader::read('character_spells.csv') as $row) {
            $grouped[$row['character_code']][] = $row;
        }

        foreach ($grouped as $characterCode => $rows) {
            $character = $characters->get($characterCode);
            if ($character === null) {
                continue;
            }

            $sync = [];
            foreach ($rows as $row) {
                $spell = $spells->get($row['spell_code']);
                if ($spell === null) {
                    continue;
                }
                $sync[$spell->id] = ['sort_order' => (int) $row['sort_order']];
            }

            $character->spells()->sync($sync);
        }

        foreach (CsvMasterReader::read('level_masters.csv') as $row) {
            LevelMaster::query()->updateOrCreate(
                ['level' => (int) $row['level']],
                [
                    'exp_to_next' => (int) $row['exp_to_next'],
                    'hp_growth' => (float) $row['hp_growth'],
                    'mp_growth' => (float) $row['mp_growth'],
                    'stat_growth' => (float) $row['stat_growth'],
                ],
            );
        }
    }
}
