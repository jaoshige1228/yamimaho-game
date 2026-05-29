<?php

namespace App\Services\Battle;

/**
 * 自動シミュレーション用: 味方ターンの行動を決める戦略。
 *
 * @phpstan-type ActionChoice array{action: string, spell_id?: string|null, target_id?: string|null}
 */
interface BattleActionStrategy
{
    /**
     * @param  array<string, mixed>  $state  生の戦闘 state（DB 保存形式）
     * @return ActionChoice
     */
    public function choose(array $state): array;
}
