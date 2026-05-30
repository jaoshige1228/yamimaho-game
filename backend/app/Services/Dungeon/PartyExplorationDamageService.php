<?php

namespace App\Services\Dungeon;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Player\UserCharacterService;

class PartyExplorationDamageService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
    ) {}

    public function applyDamageToSlot(User $user, string $slotId, int $damage): void
    {
        $party = $this->characters->partyInSlotOrder($user);
        /** @var UserCharacter|null $character */
        $character = $party->get($slotId);
        if ($character === null) {
            return;
        }

        $character->hp = max(0, (int) $character->hp - $damage);
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
}
