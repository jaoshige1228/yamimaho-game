<?php

namespace App\Services\Dungeon;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\UserCharacterService;

class PartyExplorationDamageService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
    ) {}

    public function applyDamageToSlot(User $user, string $slotId, int $damage, int $minHp = 0): void
    {
        $party = $this->characters->partyInSlotOrder($user);
        /** @var UserCharacter|null $character */
        $character = $party->get($slotId);
        if ($character === null) {
            return;
        }

        $nextHp = (int) $character->hp - $damage;
        if ($minHp > 0) {
            $nextHp = max($minHp, $nextHp);
        } else {
            $nextHp = max(0, $nextHp);
        }

        $character->hp = $nextHp;
        $character->save();
    }

    /**
     * @return list<string>
     */
    public function aliveSlotIds(User $user): array
    {
        $party = $this->characters->partyInSlotOrder($user);
        $alive = [];

        foreach ($party as $slotId => $character) {
            if ($character->hp > 0) {
                $alive[] = $slotId;
            }
        }

        return $alive;
    }

    public function pickRandomAliveSlot(User $user, ?string $except = null): ?string
    {
        $alive = $this->aliveSlotIds($user);
        if ($except !== null) {
            $alive = array_values(array_filter($alive, fn (string $id) => $id !== $except));
        }

        if ($alive === []) {
            return null;
        }

        return $alive[array_rand($alive)];
    }

    public function restorePartyToFull(User $user): void
    {
        $party = $this->characters->ensureParty($user);

        foreach ($party as $character) {
            $master = $character->characterMaster;
            $stats = $this->growth->statsForLevel($master, $character->level);

            $character->hp = $stats['hp'];
            $character->mp = $stats['mp'];
            $character->save();
        }
    }

    public function restorePartyByPercent(User $user, int $percent): void
    {
        $percent = max(1, min(99, $percent));
        $party = $this->characters->ensureParty($user);

        foreach ($party as $character) {
            $master = $character->characterMaster;
            $stats = $this->growth->statsForLevel($master, $character->level);
            $maxHp = (int) $stats['hp'];
            $maxMp = (int) $stats['mp'];
            $hpGain = intdiv($maxHp * $percent, 100);
            $mpGain = intdiv($maxMp * $percent, 100);

            $character->hp = min($maxHp, (int) $character->hp + $hpGain);
            $character->mp = min($maxMp, (int) $character->mp + $mpGain);
            $character->save();
        }
    }

    public function applyDamageToParty(User $user, int $damage): void
    {
        $party = $this->characters->partyInSlotOrder($user);

        foreach ($party as $character) {
            $character->hp = max(0, (int) $character->hp - $damage);
            $character->save();
        }
    }

    public function isPartyWiped(User $user): bool
    {
        return $this->aliveSlotIds($user) === [];
    }
}
