<?php

namespace App\Services\Player;

use App\Models\ArmorMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;

class EquipmentShopService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly PartyGoldService $gold = new PartyGoldService,
        private readonly EquipmentOwnershipService $ownership = new EquipmentOwnershipService,
        private readonly EquipmentService $equipment = new EquipmentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $user): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $weapons = WeaponMaster::query()
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->orderBy('price')
            ->get();
        $armors = ArmorMaster::query()
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->orderBy('price')
            ->get();

        $characters = [];
        foreach ($party as $slotId => $character) {
            $ownedWeapons = collect($this->ownership->ownedWeaponsPayload($character))->pluck('code')->all();
            $ownedArmors = collect($this->ownership->ownedArmorsPayload($character))->pluck('code')->all();
            $combat = $this->equipment->effectiveCombatStats($character);

            $characters[] = [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'sprite' => $character->characterMaster->sprite,
                'equipped_weapon' => $combat['weapon'],
                'equipped_armor' => $combat['armor'],
                'owned_weapon_codes' => $ownedWeapons,
                'owned_armor_codes' => $ownedArmors,
            ];
        }

        return [
            'gold' => $this->gold->getGold($user),
            'weapons' => $weapons->map(fn (WeaponMaster $w) => [
                'code' => $w->code,
                'name' => $w->name,
                'mag_bonus' => $w->mag_bonus,
                'description' => $w->description,
                'price' => $w->price,
            ])->values()->all(),
            'armors' => $armors->map(fn (ArmorMaster $a) => [
                'code' => $a->code,
                'name' => $a->name,
                'def_bonus' => $a->def_bonus,
                'description' => $a->description,
                'price' => $a->price,
            ])->values()->all(),
            'characters' => $characters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buy(User $user, string $slotId, string $kind, string $code): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $character = $party->get($slotId);
        if ($character === null) {
            throw new \InvalidArgumentException('キャラクターが見つかりません。');
        }

        if ($kind === 'weapon') {
            $weapon = WeaponMaster::query()->where('code', $code)->first();
            if ($weapon === null || $weapon->price === null || $weapon->price <= 0) {
                throw new \InvalidArgumentException('この武器は購入できません。');
            }
            if ($this->ownership->ownsWeapon($character, $code)) {
                throw new \InvalidArgumentException('すでに所持しています。');
            }

            $this->gold->spendGold($user, (int) $weapon->price);
            $this->ownership->grantWeapon($character, $weapon);
            $equipped = $this->equipWeaponIfStronger($character, $weapon);

            return [
                'message' => "{$character->characterMaster->name}が{$weapon->name}を購入".($equipped ? 'し、装備した。' : 'した。'),
                'equipped' => $equipped,
                'gold' => $this->gold->getGold($user->fresh()),
                'catalog' => $this->catalog($user->fresh()),
            ];
        }

        if ($kind === 'armor') {
            $armor = ArmorMaster::query()->where('code', $code)->first();
            if ($armor === null || $armor->price === null || $armor->price <= 0) {
                throw new \InvalidArgumentException('この防具は購入できません。');
            }
            if ($this->ownership->ownsArmor($character, $code)) {
                throw new \InvalidArgumentException('すでに所持しています。');
            }

            $this->gold->spendGold($user, (int) $armor->price);
            $this->ownership->grantArmor($character, $armor);
            $equipped = $this->equipArmorIfStronger($character, $armor);

            return [
                'message' => "{$character->characterMaster->name}が{$armor->name}を購入".($equipped ? 'し、装備した。' : 'した。'),
                'equipped' => $equipped,
                'gold' => $this->gold->getGold($user->fresh()),
                'catalog' => $this->catalog($user->fresh()),
            ];
        }

        throw new \InvalidArgumentException('種別が不正です。');
    }

    /**
     * 買った武器が装備中のものより強い（装備なしを含む）ときだけ、そのまま装備する。
     * 魔力ボーナスが同じ・低いときは今の装備を変えない。
     */
    private function equipWeaponIfStronger(UserCharacter $character, WeaponMaster $weapon): bool
    {
        $character->loadMissing('weapon');
        if ($character->weapon !== null && $weapon->mag_bonus <= $character->weapon->mag_bonus) {
            return false;
        }

        $character->weapon_master_id = $weapon->id;
        $character->save();
        $character->setRelation('weapon', $weapon);

        return true;
    }

    /**
     * 買った防具が装備中のものより強い（装備なしを含む）ときだけ、そのまま装備する。
     * 防御ボーナスが同じ・低いときは今の装備を変えない。
     */
    private function equipArmorIfStronger(UserCharacter $character, ArmorMaster $armor): bool
    {
        $character->loadMissing('armor');
        if ($character->armor !== null && $armor->def_bonus <= $character->armor->def_bonus) {
            return false;
        }

        $character->armor_master_id = $armor->id;
        $character->save();
        $character->setRelation('armor', $armor);

        return true;
    }
}
