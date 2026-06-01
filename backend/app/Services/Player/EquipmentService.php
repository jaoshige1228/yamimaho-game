<?php

namespace App\Services\Player;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;

class EquipmentService
{
    /**
     * @return array{
     *   mag: int,
     *   def: int,
     *   weapon: array{code: string, name: string, mag_bonus: int, description: string|null}|null,
     *   armor: array{code: string, name: string, def_bonus: int, description: string|null}|null
     * }
     */
    public function effectiveCombatStats(UserCharacter $character): array
    {
        $character->loadMissing(['weapon', 'armor']);

        $weapon = $character->weapon;
        $armor = $character->armor;

        return [
            'mag' => $character->mag + ($weapon?->mag_bonus ?? 0),
            'def' => $character->def + ($armor?->def_bonus ?? 0),
            'weapon' => $weapon !== null ? $this->weaponPayload($weapon) : null,
            'armor' => $armor !== null ? $this->armorPayload($armor) : null,
        ];
    }

    public function assignDefaultsIfMissing(UserCharacter $character, CharacterMaster $master): void
    {
        $dirty = false;

        if ($character->weapon_master_id === null && $master->default_weapon_code !== null) {
            $weapon = WeaponMaster::query()->where('code', $master->default_weapon_code)->first();
            if ($weapon !== null) {
                $character->weapon_master_id = $weapon->id;
                $dirty = true;
            }
        }

        if ($character->armor_master_id === null && $master->default_armor_code !== null) {
            $armor = ArmorMaster::query()->where('code', $master->default_armor_code)->first();
            if ($armor !== null) {
                $character->armor_master_id = $armor->id;
                $dirty = true;
            }
        }

        if ($dirty) {
            $character->save();
        }
    }

    /**
     * @return array{code: string, name: string, mag_bonus: int, description: string|null}
     */
    public function weaponPayload(WeaponMaster $weapon): array
    {
        return [
            'code' => $weapon->code,
            'name' => $weapon->name,
            'mag_bonus' => $weapon->mag_bonus,
            'description' => $weapon->description,
        ];
    }

    /**
     * @return array{code: string, name: string, def_bonus: int, description: string|null}
     */
    public function armorPayload(ArmorMaster $armor): array
    {
        return [
            'code' => $armor->code,
            'name' => $armor->name,
            'def_bonus' => $armor->def_bonus,
            'description' => $armor->description,
        ];
    }
}
