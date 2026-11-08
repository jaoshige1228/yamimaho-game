<?php

namespace App\Services\Admin;

use App\Models\ArmorMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\EquipmentOwnershipService;
use App\Services\Player\PartyGoldService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Facades\DB;

class AdminPartyService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly EquipmentOwnershipService $ownership = new EquipmentOwnershipService,
        private readonly PartyGoldService $gold = new PartyGoldService,
    ) {}

    /**
     * 管理画面の装備操作用。選べる武器・防具（全マスター）と、いまの味方の装備。
     *
     * @return array{weapons: list<array<string, mixed>>, armors: list<array<string, mixed>>, characters: list<array<string, mixed>>}
     */
    public function equipmentPayload(User $user): array
    {
        $this->characters->ensureParty($user);

        return [
            'weapons' => WeaponMaster::query()
                ->orderBy('mag_bonus')
                ->orderBy('code')
                ->get()
                ->map(fn (WeaponMaster $weapon) => [
                    'code' => $weapon->code,
                    'name' => $weapon->name,
                    'mag_bonus' => (int) $weapon->mag_bonus,
                ])
                ->values()
                ->all(),
            'armors' => ArmorMaster::query()
                ->orderBy('def_bonus')
                ->orderBy('code')
                ->get()
                ->map(fn (ArmorMaster $armor) => [
                    'code' => $armor->code,
                    'name' => $armor->name,
                    'def_bonus' => (int) $armor->def_bonus,
                ])
                ->values()
                ->all(),
            'characters' => $this->characters->partyInSlotOrder($user)
                ->map(fn (UserCharacter $character, string $slotId) => [
                    'slot_id' => $slotId,
                    'name' => $character->characterMaster->name,
                    'level' => (int) $character->level,
                    'weapon_code' => $character->weapon?->code,
                    'armor_code' => $character->armor?->code,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * 味方の装備を、持っているかどうかに関係なく好きなものに替える。
     * 替えた装備は所持扱いにする（プレイヤーの装備画面で外したあとも、同じものを付け直せるように）。
     * 武器・防具は片方だけでも替えられる（null のほうは今のまま）。装備なしにはできない
     * （装備なしのキャラは、パーティ取得のたびに初期装備へ戻されるため）。
     *
     * @return array<string, mixed>
     */
    public function equip(User $user, string $slotId, ?string $weaponCode, ?string $armorCode): array
    {
        $character = $this->characters->ensureParty($user)->get($slotId);
        if ($character === null) {
            throw new \InvalidArgumentException('キャラクターが見つかりません。');
        }

        DB::transaction(function () use ($character, $weaponCode, $armorCode): void {
            if ($weaponCode !== null) {
                $weapon = WeaponMaster::query()->where('code', $weaponCode)->firstOrFail();
                $character->weapon_master_id = $weapon->id;
                $this->ownership->grantWeapon($character, $weapon);
            }
            if ($armorCode !== null) {
                $armor = ArmorMaster::query()->where('code', $armorCode)->firstOrFail();
                $character->armor_master_id = $armor->id;
                $this->ownership->grantArmor($character, $armor);
            }
            $character->save();
        });

        return $this->equipmentPayload($user);
    }

    /** users.gold（符号なし整数）の上限。これを超えないよう、合計を丸める */
    private const GOLD_MAX = 4294967295;

    /**
     * パーティーの所持金に、指定したゴールドを足す。
     *
     * @return int 足したあとの所持金
     */
    public function grantGold(User $user, int $amount): int
    {
        $user->refresh();

        return $this->gold->setGold($user, min(self::GOLD_MAX, $this->gold->getGold($user) + max(0, $amount)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grantExpToParty(User $user, int $exp): array
    {
        $party = $this->characters->ensureParty($user);
        $results = [];

        DB::transaction(function () use ($party, $exp, &$results): void {
            foreach ($party as $slotId => $character) {
                /** @var UserCharacter $character */
                $character->load('characterMaster');
                $beforeLevel = $character->level;

                $levelEvents = $this->growth->grantExp($character, $exp);
                $character->save();

                $results[] = [
                    'slot_id' => $slotId,
                    'name' => $character->characterMaster->name,
                    'level' => $character->level,
                    'exp' => $character->exp,
                    'levels_gained' => $character->level - $beforeLevel,
                    'level_ups' => $levelEvents,
                ];
            }
        });

        return $results;
    }
}
