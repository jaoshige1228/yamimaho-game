<?php

namespace App\Services\Battle;

use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use Illuminate\Support\Facades\DB;

class BattleRewardService
{
    public function __construct(
        private readonly LevelGrowthService $growth = new LevelGrowthService,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    public function applyVictoryRewards(array $state, User $user): array
    {
        $expEach = $this->totalExpEarned($state);
        $events = [];

        DB::transaction(function () use ($state, $user, $expEach, &$events): void {
            foreach ($state['units'] as $unit) {
                if (($unit['side'] ?? '') !== 'ally') {
                    continue;
                }

                if (! ($unit['alive'] ?? false)) {
                    continue;
                }

                $userCharacterId = $unit['user_character_id'] ?? null;
                if ($userCharacterId === null) {
                    continue;
                }

                /** @var UserCharacter|null $character */
                $character = UserCharacter::query()
                    ->where('user_id', $user->id)
                    ->whereKey($userCharacterId)
                    ->with('characterMaster')
                    ->first();

                if ($character === null) {
                    continue;
                }

                $character->hp = max(0, (int) ($unit['hp'] ?? 0));
                $character->mp = max(0, (int) ($unit['mp'] ?? 0));
                $character->save();

                if ($expEach > 0) {
                    $levelEvents = $this->growth->grantExp($character, $expEach);
                    $character->save();

                    foreach ($levelEvents as $levelEvent) {
                        $events[] = $levelEvent;
                    }

                    $events[] = [
                        'type' => 'exp_gained',
                        'slot_id' => $unit['id'],
                        'name' => $unit['name'],
                        'amount' => $expEach,
                    ];
                }
            }
        });

        return $events;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function totalExpEarned(array $state): int
    {
        return max(0, (int) ($state['meta']['exp_earned'] ?? 0));
    }

    /**
     * 敗北時も HP/MP だけは保存する。
     *
     * @param  array<string, mixed>  $state
     */
    public function syncPartyHpMp(array $state, User $user): void
    {
        foreach ($state['units'] as $unit) {
            if (($unit['side'] ?? '') !== 'ally') {
                continue;
            }

            $userCharacterId = $unit['user_character_id'] ?? null;
            if ($userCharacterId === null) {
                continue;
            }

            UserCharacter::query()
                ->where('user_id', $user->id)
                ->whereKey($userCharacterId)
                ->update([
                    'hp' => max(0, (int) ($unit['hp'] ?? 0)),
                    'mp' => max(0, (int) ($unit['mp'] ?? 0)),
                ]);
        }
    }
}
