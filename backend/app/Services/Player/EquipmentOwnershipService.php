<?php

namespace App\Services\Player;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\UserCharacter;
use App\Models\UserCharacterOwnedArmor;
use App\Models\UserCharacterOwnedWeapon;
use App\Models\WeaponMaster;

class EquipmentOwnershipService
{
    public function ensureDefaultOwnership(UserCharacter $character, CharacterMaster $master): void
    {
        if ($master->default_weapon_code !== null) {
            $weapon = WeaponMaster::query()->where('code', $master->default_weapon_code)->first();
            if ($weapon !== null) {
                UserCharacterOwnedWeapon::query()->firstOrCreate([
                    'user_character_id' => $character->id,
                    'weapon_master_id' => $weapon->id,
                ]);
            }
        }

        if ($master->default_armor_code !== null) {
            $armor = ArmorMaster::query()->where('code', $master->default_armor_code)->first();
            if ($armor !== null) {
                UserCharacterOwnedArmor::query()->firstOrCreate([
                    'user_character_id' => $character->id,
                    'armor_master_id' => $armor->id,
                ]);
            }
        }
    }

    public function ownsWeapon(UserCharacter $character, string $weaponCode): bool
    {
        return UserCharacterOwnedWeapon::query()
            ->where('user_character_id', $character->id)
            ->whereHas('weaponMaster', fn ($q) => $q->where('code', $weaponCode))
            ->exists();
    }

    public function ownsArmor(UserCharacter $character, string $armorCode): bool
    {
        return UserCharacterOwnedArmor::query()
            ->where('user_character_id', $character->id)
            ->whereHas('armorMaster', fn ($q) => $q->where('code', $armorCode))
            ->exists();
    }

    public function grantWeapon(UserCharacter $character, WeaponMaster $weapon): void
    {
        UserCharacterOwnedWeapon::query()->firstOrCreate([
            'user_character_id' => $character->id,
            'weapon_master_id' => $weapon->id,
        ]);
    }

    public function grantArmor(UserCharacter $character, ArmorMaster $armor): void
    {
        UserCharacterOwnedArmor::query()->firstOrCreate([
            'user_character_id' => $character->id,
            'armor_master_id' => $armor->id,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ownedWeaponsPayload(UserCharacter $character): array
    {
        return UserCharacterOwnedWeapon::query()
            ->where('user_character_id', $character->id)
            ->with('weaponMaster')
            ->get()
            ->map(fn (UserCharacterOwnedWeapon $row) => [
                'code' => $row->weaponMaster->code,
                'name' => $row->weaponMaster->name,
                'mag_bonus' => $row->weaponMaster->mag_bonus,
                'description' => $row->weaponMaster->description,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ownedArmorsPayload(UserCharacter $character): array
    {
        return UserCharacterOwnedArmor::query()
            ->where('user_character_id', $character->id)
            ->with('armorMaster')
            ->get()
            ->map(fn (UserCharacterOwnedArmor $row) => [
                'code' => $row->armorMaster->code,
                'name' => $row->armorMaster->name,
                'def_bonus' => $row->armorMaster->def_bonus,
                'description' => $row->armorMaster->description,
            ])
            ->values()
            ->all();
    }
}
