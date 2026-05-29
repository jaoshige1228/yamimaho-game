<?php

namespace App\Services\Player;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Models\WeaponMaster;
use App\Services\Growth\LevelGrowthService;
use Illuminate\Support\Collection;

class UserCharacterService
{
    public function __construct(
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly EquipmentService $equipment = new EquipmentService,
    ) {}

    /**
     * @return Collection<int, UserCharacter>
     */
    public function ensureParty(User $user): Collection
    {
        $party = UserCharacter::query()
            ->where('user_id', $user->id)
            ->with(['characterMaster', 'weapon', 'armor'])
            ->get()
            ->keyBy(fn (UserCharacter $c) => $c->characterMaster->code);

        foreach (config('game.party_slots', []) as $masterCode) {
            if ($party->has($masterCode)) {
                $existing = $party->get($masterCode);
                $this->equipment->assignDefaultsIfMissing($existing, $existing->characterMaster);

                continue;
            }

            $master = CharacterMaster::query()->where('code', $masterCode)->firstOrFail();
            $stats = $this->growth->statsForLevel($master, 1);

            $weaponId = null;
            $armorId = null;
            if ($master->default_weapon_code !== null) {
                $weaponId = WeaponMaster::query()
                    ->where('code', $master->default_weapon_code)
                    ->value('id');
            }
            if ($master->default_armor_code !== null) {
                $armorId = ArmorMaster::query()
                    ->where('code', $master->default_armor_code)
                    ->value('id');
            }

            $created = UserCharacter::query()->create([
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
                'weapon_master_id' => $weaponId,
                'armor_master_id' => $armorId,
            ]);

            $created->load(['characterMaster', 'weapon', 'armor']);
            $party->put($masterCode, $created);
        }

        return $this->partyInSlotOrder($user);
    }

    /**
     * @return Collection<int, UserCharacter>
     */
    public function partyInSlotOrder(User $user): Collection
    {
        $byCode = UserCharacter::query()
            ->where('user_id', $user->id)
            ->with(['characterMaster', 'weapon', 'armor'])
            ->get()
            ->keyBy(fn (UserCharacter $c) => $c->characterMaster->code);

        $ordered = collect();
        foreach (config('game.party_slots', []) as $slotId => $masterCode) {
            $character = $byCode->get($masterCode);
            if ($character !== null) {
                $ordered->put($slotId, $character);
            }
        }

        return $ordered;
    }

    public function resetParty(User $user): void
    {
        UserCharacter::query()->where('user_id', $user->id)->delete();
        $this->ensureParty($user);
    }

    public function restorePartyToFull(User $user): void
    {
        $party = $this->ensureParty($user);

        foreach ($party as $character) {
            $master = $character->characterMaster;
            $stats = $this->growth->statsForLevel($master, $character->level);

            $character->hp = $stats['hp'];
            $character->mp = $stats['mp'];
            $character->str = $stats['str'];
            $character->mag = $stats['mag'];
            $character->def = $stats['def'];
            $character->spd = $stats['spd'];
            $character->know = $stats['know'];
            $character->spirit = $stats['spirit'];
            $character->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPartyUnitPayload(UserCharacter $character, string $slotId): array
    {
        $master = $character->characterMaster;
        $maxStats = $this->growth->statsForLevel($master, $character->level);
        $combat = $this->equipment->effectiveCombatStats($character);

        return [
            'id' => $slotId,
            'slot_id' => $slotId,
            'side' => 'ally',
            'name' => $master->name,
            'sprite' => $master->sprite,
            'level' => $character->level,
            'exp' => $character->exp,
            'hp' => $character->hp,
            'max_hp' => $maxStats['hp'],
            'mp' => $character->mp,
            'max_mp' => $maxStats['mp'],
            'alive' => $character->hp > 0,
            'str' => $character->str,
            'mag' => $combat['mag'],
            'def' => $combat['def'],
            'spd' => $character->spd,
            'know' => $character->know,
            'spirit' => $character->spirit,
            'weapon' => $combat['weapon'],
            'armor' => $combat['armor'],
        ];
    }
}
