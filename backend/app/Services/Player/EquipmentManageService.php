<?php

namespace App\Services\Player;

use App\Models\ArmorMaster;
use App\Models\User;
use App\Models\WeaponMaster;

class EquipmentManageService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly EquipmentOwnershipService $ownership = new EquipmentOwnershipService,
        private readonly EquipmentService $equipment = new EquipmentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(User $user): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $characters = [];

        foreach ($party as $slotId => $character) {
            $combat = $this->equipment->effectiveCombatStats($character);
            $characters[] = [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'sprite' => $character->characterMaster->sprite,
                'equipped_weapon' => $combat['weapon'],
                'equipped_armor' => $combat['armor'],
                'owned_weapons' => $this->ownership->ownedWeaponsPayload($character),
                'owned_armors' => $this->ownership->ownedArmorsPayload($character),
            ];
        }

        return ['characters' => $characters];
    }

    /**
     * @return array<string, mixed>
     */
    public function equip(User $user, string $slotId, ?string $weaponCode = null, ?string $armorCode = null): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $character = $party->get($slotId);
        if ($character === null) {
            throw new \InvalidArgumentException('キャラクターが見つかりません。');
        }

        if ($weaponCode !== null) {
            if (! $this->ownership->ownsWeapon($character, $weaponCode)) {
                throw new \InvalidArgumentException('その武器を所持していません。');
            }
            $weapon = WeaponMaster::query()->where('code', $weaponCode)->firstOrFail();
            $character->weapon_master_id = $weapon->id;
        }

        if ($armorCode !== null) {
            if (! $this->ownership->ownsArmor($character, $armorCode)) {
                throw new \InvalidArgumentException('その防具を所持していません。');
            }
            $armor = ArmorMaster::query()->where('code', $armorCode)->firstOrFail();
            $character->armor_master_id = $armor->id;
        }

        $character->save();

        return [
            'message' => '装備を変更した。',
            'equipment' => $this->payload($user),
        ];
    }
}
