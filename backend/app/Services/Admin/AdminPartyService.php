<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Facades\DB;

class AdminPartyService
{
    public function __construct(
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
    ) {}

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
