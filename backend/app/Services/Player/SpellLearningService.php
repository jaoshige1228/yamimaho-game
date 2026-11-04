<?php

namespace App\Services\Player;

use App\Models\User;
use App\Models\UserCharacter;
use App\Models\UserCharacterLearnedSpell;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\MasterDataProvider;
use Illuminate\Support\Facades\DB;

/**
 * 「敵を倒して覚える魔法」（character_spells.csv の learn_after_defeat）の習得状態を扱う。
 * 習得済みの魔法は user_character_learned_spells に残り、データのリセット（キャラの作り直し）で消える。
 */
class SpellLearningService
{
    public function __construct(
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        private readonly UserCharacterService $characters = new UserCharacterService,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
    ) {}

    /**
     * キャラクターが今使える魔法（並び順どおり）。
     *
     * @return list<string>
     */
    public function spellIdsFor(UserCharacter $character): array
    {
        $masterCode = $character->characterMaster->code;

        return $this->masters->spellIdsForCharacter($masterCode, $this->learnedSpellCodes($character));
    }

    /**
     * @return list<string>
     */
    public function learnedSpellCodes(UserCharacter $character): array
    {
        return UserCharacterLearnedSpell::query()
            ->where('user_character_id', $character->id)
            ->pluck('spell_code')
            ->all();
    }

    /**
     * 勝利した戦闘で倒した敵に応じて、パーティ全員が新しい魔法を覚える（戦闘不能のキャラも含む）。
     * すでに覚えている魔法は対象外なので、同じ敵を何度倒しても覚えるのは最初の1回だけ。
     * 同じ「初回」のタイミングで、`game.first_defeat_max_mp_bonus` に設定されたキャラの最大MPも加算する（文言は出さない）。
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>  覚えた人がいれば spells_learned イベント 1 件、いなければ空
     */
    public function learnAfterVictory(array $state, User $user): array
    {
        $defeatedCodes = [];
        foreach ($state['units'] ?? [] as $unit) {
            // 逃げた敵は倒したことにならない
            if (($unit['side'] ?? '') === 'enemy' && ! ($unit['fled'] ?? false) && isset($unit['master_code'])) {
                $defeatedCodes[(string) $unit['master_code']] = true;
            }
        }
        if ($defeatedCodes === []) {
            return [];
        }
        $defeatedCodes = array_keys($defeatedCodes);

        $learnedBy = [];
        foreach ($this->characters->ensureParty($user) as $slotId => $character) {
            $alreadyLearned = $this->learnedSpellCodes($character);
            $newSpellIds = array_values(array_filter(
                $this->masters->spellIdsLearnedByDefeating($character->characterMaster->code, $defeatedCodes),
                fn (string $spellId) => ! in_array($spellId, $alreadyLearned, true),
            ));
            if ($newSpellIds === []) {
                continue;
            }

            $spells = [];
            DB::transaction(function () use ($character, $newSpellIds, $defeatedCodes, $alreadyLearned, &$spells): void {
                foreach ($newSpellIds as $spellId) {
                    UserCharacterLearnedSpell::query()->firstOrCreate([
                        'user_character_id' => $character->id,
                        'spell_code' => $spellId,
                    ]);
                    $spells[] = ['id' => $spellId, 'label' => $this->masters->findSpell($spellId)['label']];
                }

                $this->grantFirstDefeatMaxMpBonus($character, $defeatedCodes, $alreadyLearned);
            });

            $learnedBy[] = [
                'slot_id' => $slotId,
                'name' => $character->characterMaster->name,
                'spells' => $spells,
            ];
        }

        if ($learnedBy === []) {
            return [];
        }

        return [['type' => 'spells_learned', 'characters' => $learnedBy]];
    }

    /**
     * 倒した敵ごとに、そのキャラがその敵で覚える魔法をまだ一つも覚えていなかった（= 初回）なら、最大MPを加算する。
     * 現在のMPも同じだけ増やす（レベルアップ時と同じ扱い）。
     *
     * @param  list<string>  $defeatedCodes
     * @param  list<string>  $alreadyLearned  この戦闘より前に覚えていた魔法
     */
    private function grantFirstDefeatMaxMpBonus(UserCharacter $character, array $defeatedCodes, array $alreadyLearned): void
    {
        $masterCode = $character->characterMaster->code;
        $bonus = 0;

        foreach ($defeatedCodes as $enemyCode) {
            $amount = (int) config("game.first_defeat_max_mp_bonus.{$enemyCode}.{$masterCode}", 0);
            if ($amount <= 0) {
                continue;
            }

            $spellsOfEnemy = $this->masters->spellIdsLearnedByDefeating($masterCode, [$enemyCode]);
            if (array_intersect($spellsOfEnemy, $alreadyLearned) === []) {
                $bonus += $amount;
            }
        }

        if ($bonus <= 0) {
            return;
        }

        $character->max_mp_bonus = (int) $character->max_mp_bonus + $bonus;
        $maxMp = $this->growth->statsForCharacter($character)['mp'];
        $character->mp = min($maxMp, (int) $character->mp + $bonus);
        $character->save();
    }
}
