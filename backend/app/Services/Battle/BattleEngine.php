<?php

namespace App\Services\Battle;

use InvalidArgumentException;

class BattleEngine
{
    /** @var (callable(array<string, array<string, mixed>>): ?string)|null */
    private $enemyTargetPicker = null;

    /** @var (callable(): float)|null */
    private $damageRoll = null;

    /** @var (callable(): int)|null */
    private $hitRoll = null;

    /** @var (callable(): int)|null */
    private $critRoll = null;

    /**
     * @param  (callable(array<string, array<string, mixed>>): ?string)|null  $enemyTargetPicker
     * @param  (callable(): float)|null  $damageRoll
     * @param  (callable(): int)|null  $hitRoll  1〜100
     * @param  (callable(): int)|null  $critRoll  1〜100
     */
    public function __construct(
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly EnemySkillPicker $enemySkillPicker = new EnemySkillPicker,
        ?callable $enemyTargetPicker = null,
        ?callable $damageRoll = null,
        ?callable $hitRoll = null,
        ?callable $critRoll = null,
    ) {
        $this->enemyTargetPicker = $enemyTargetPicker;
        $this->damageRoll = $damageRoll;
        $this->hitRoll = $hitRoll;
        $this->critRoll = $critRoll;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function applyPlayerAction(array $state, string $actorId, string $action, ?string $spellId = null, ?string $targetId = null): array
    {
        if ($state['status'] !== 'active') {
            throw new InvalidArgumentException('戦闘は既に終了しています。');
        }

        if (($state['current_actor'] ?? null) !== $actorId) {
            throw new InvalidArgumentException('現在のターンではありません。');
        }

        if (! str_starts_with($actorId, 'pc')) {
            throw new InvalidArgumentException('プレイヤー以外は操作できません。');
        }

        $events = [['type' => 'turn_start', 'actor' => $actorId]];
        $events = array_merge($events, $this->resolveAction($state, $actorId, $action, $spellId, $targetId));
        $this->tickBuffsForUnit($state['units'][$actorId]);

        return $this->afterAction($state, $events, $actorId);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function runEnemyTurnsUntilPlayer(array $state): array
    {
        $allEvents = [];

        while ($state['status'] === 'active' && ! ($state['awaiting_input'] ?? false)) {
            $actor = $state['current_actor'] ?? null;
            if ($actor === null || ! str_starts_with($actor, 'enemy')) {
                break;
            }

            $actorUnit = $state['units'][$actor];
            $skill = $this->enemySkillPicker->pick((string) ($actorUnit['master_code'] ?? ''));

            $events = [['type' => 'turn_start', 'actor' => $actor]];
            $events = array_merge($events, $this->resolveEnemySkill($state, $actor, $skill));
            $this->tickBuffsForUnit($state['units'][$actor]);

            $result = $this->afterAction($state, $events, $actor);
            $state = $result['state'];
            $allEvents = array_merge($allEvents, $result['events']);
        }

        return ['state' => $state, 'events' => $allEvents];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $events
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function afterAction(array $state, array $events, string $actorId): array
    {
        $end = $this->checkBattleEnd($state);
        if ($end !== null) {
            $state['status'] = $end;
            $state['awaiting_input'] = false;
            $state['current_actor'] = null;
            $events[] = ['type' => 'battle_end', 'result' => $end];
            $state['log'][] = $end === 'victory' ? '勝利！' : '敗北…';

            return ['state' => $state, 'events' => $events];
        }

        $nextIndex = $this->nextTurnIndex($state, $actorId);
        $nextActor = $this->factory->findNextActor($state['units'], $state['turn_order'], $nextIndex);

        $state['turn_index'] = $nextIndex;
        $state['current_actor'] = $nextActor;
        $state['awaiting_input'] = $nextActor !== null && str_starts_with($nextActor, 'pc');

        return ['state' => $state, 'events' => $events];
    }

    /**
     * @param  array<string, mixed>  $skill
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolveEnemySkill(array &$state, string $actorId, array $skill): array
    {
        $actor = $state['units'][$actorId];
        $announceText = $skill['skill_code'] === 'attack'
            ? "{$actor['name']}の攻撃！"
            : "{$actor['name']}の{$skill['label']}！";

        $events = [$this->announceEvent($actorId, $announceText)];

        $targetType = (string) $skill['target_type'];
        $coefficient = (float) $skill['coefficient'];
        $actionType = (string) $skill['action_type'];

        if ($targetType === 'ally_all') {
            foreach ($this->resolveEnemySkillTargets('ally_all', $state['units']) as $tid) {
                $events = array_merge(
                    $events,
                    $this->resolvePhysicalHit($state, $actorId, $tid, $coefficient, null, false),
                );
            }

            return $events;
        }

        $target = $this->pickRandomAliveAlly($state['units']);
        if ($target === null) {
            return $events;
        }

        if ($actionType === 'punch' || $actionType === 'physical_single') {
            return array_merge(
                $events,
                $this->resolvePhysicalHit($state, $actorId, $target, $coefficient, null, false),
            );
        }

        throw new InvalidArgumentException("不明な敵行動です: {$actionType}");
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolveAction(array &$state, string $actorId, string $action, ?string $spellId, ?string $targetId): array
    {
        $actor = &$state['units'][$actorId];
        $events = [];

        if ($action === 'punch') {
            $targetSide = str_starts_with($actorId, 'enemy') ? 'ally_single' : 'enemy_single';
            $this->assertTarget($targetId, $targetSide, $state['units']);
            $coefficient = (float) config('battle.common_actions.punch.coefficient', 1.0);
            $events[] = $this->announceEvent($actorId, "{$actor['name']}のこぶし！");

            return array_merge(
                $events,
                $this->resolvePhysicalHit($state, $actorId, (string) $targetId, $coefficient, null, true),
            );
        }

        if ($action === 'kick') {
            $this->assertTarget($targetId, 'enemy_single', $state['units']);
            $coefficient = (float) config('battle.common_actions.kick.coefficient', 2.0);
            $hitRate = (int) config('battle.common_actions.kick.hit_rate', 50);
            $events[] = $this->announceEvent($actorId, "{$actor['name']}のキック！");

            if ($this->rollHit($hitRate) === false) {
                $state['log'][] = "{$actor['name']}のキックは外れた";
                $events[] = ['type' => 'miss', 'actor' => $actorId, 'target' => $targetId];

                return $events;
            }

            return array_merge(
                $events,
                $this->resolvePhysicalHit($state, $actorId, (string) $targetId, $coefficient, null, false),
            );
        }

        if ($action !== 'spell' || $spellId === null) {
            throw new InvalidArgumentException('不明な行動です。');
        }

        if (! in_array($spellId, $actor['spells'], true)) {
            throw new InvalidArgumentException('この魔法は使えません。');
        }

        $spell = $this->factory->findSpell($spellId);
        $mpCost = (int) $spell['mp_cost'];
        if ($actor['mp'] < $mpCost) {
            throw new InvalidArgumentException('MPが足りません。');
        }

        $targets = $this->resolveSpellTargets($spell, $targetId, $state['units'], $actor['side']);
        foreach ($targets as $tid) {
            $this->assertSpellTargetValid($spell, $state['units'][$tid]);
        }

        $actor['mp'] -= $mpCost;
        $events[] = ['type' => 'mp_spent', 'actor' => $actorId, 'amount' => $mpCost];
        $events[] = $this->announceEvent(
            $actorId,
            "{$actor['name']}は{$spell['label']}を唱えた！",
            isset($spell['element']) && $spell['element'] !== '' ? (string) $spell['element'] : null,
            isset($spell['effect']) && $spell['effect'] !== '' ? (string) $spell['effect'] : null,
        );

        foreach ($targets as $tid) {
            $events = array_merge($events, $this->applySpellEffect($state, $actorId, $tid, $spell, $spellId));
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, mixed>  $target
     */
    private function assertSpellTargetValid(array $spell, array $target): void
    {
        $effect = $spell['effect'] ?? '';

        if (in_array($effect, ['heal_mag', 'heal'], true)) {
            if (! ($target['alive'] ?? false) || (int) $target['hp'] >= (int) $target['max_hp']) {
                throw new InvalidArgumentException('回復できない対象です。');
            }

            return;
        }

        if ($effect === 'revive_chance') {
            if ($target['alive'] ?? true) {
                throw new InvalidArgumentException('蘇生できない対象です。');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, array<string, mixed>>  $units
     */
    private function spellHasValidTarget(array $spell, array $units): bool
    {
        $effect = $spell['effect'] ?? '';
        $targetType = $spell['target_type'] ?? 'ally_single';

        if (str_starts_with($targetType, 'enemy')) {
            foreach ($units as $unit) {
                if (($unit['side'] ?? '') === 'enemy' && ($unit['alive'] ?? false)) {
                    return true;
                }
            }

            return false;
        }

        if (! str_starts_with($targetType, 'ally')) {
            return true;
        }

        foreach ($units as $unit) {
            if (($unit['side'] ?? '') !== 'ally') {
                continue;
            }

            if (in_array($effect, ['heal_mag', 'heal'], true)) {
                if (($unit['alive'] ?? false) && (int) $unit['hp'] < (int) $unit['max_hp']) {
                    return true;
                }

                continue;
            }

            if ($effect === 'revive_chance') {
                if (! ($unit['alive'] ?? true)) {
                    return true;
                }

                continue;
            }

            if ($unit['alive'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function applySpellEffect(array &$state, string $actorId, string $targetId, array $spell, string $spellId): array
    {
        $actor = $state['units'][$actorId];
        $target = &$state['units'][$targetId];
        $events = [];
        $label = $spell['label'];

        switch ($spell['effect']) {
            case 'damage':
                $coefficient = (float) ($spell['coefficient'] ?? 1.0);
                $damageResult = $this->calcDamageWithCritical($actor, $target, 'mag', $coefficient);
                $events = array_merge(
                    $events,
                    $this->applyDamage($state, $actorId, $targetId, $damageResult['damage'], $spell['element'] ?? null, $damageResult['critical']),
                );
                $state['log'][] = "{$actor['name']}の{$label}！ {$target['name']}に{$damageResult['damage']}ダメージ";
                break;

            case 'heal_mag':
                $amount = (int) floor((float) $actor['mag'] * 3);
                $healed = min($amount, $target['max_hp'] - $target['hp']);
                $target['hp'] += $healed;
                $events[] = ['type' => 'heal', 'actor' => $actorId, 'target' => $targetId, 'value' => $healed];
                $state['log'][] = "{$target['name']}は{$healed}回復した";
                break;

            case 'buff_evasion_set':
            case 'buff_def':
            case 'debuff_def':
                $buffKey = $spell['buff'];
                $target['buffs'][$buffKey] = config("battle.buffs.{$buffKey}.turns");
                $events[] = [
                    'type' => 'buff_applied',
                    'actor' => $actorId,
                    'target' => $targetId,
                    'buff' => $buffKey,
                    'icon' => config("battle.buffs.{$buffKey}.icon"),
                ];
                $state['log'][] = "{$target['name']}に{$label}";
                break;

            case 'shield_next':
                $target['damage_shield'] = true;
                $events[] = ['type' => 'shield_applied', 'actor' => $actorId, 'target' => $targetId];
                $state['log'][] = "{$target['name']}に{$label}";
                break;

            case 'revive_chance':
                if (! $target['alive']) {
                    $chance = (int) ($spell['power'] ?? 50);
                    if ($this->rollHit($chance)) {
                        $revivedHp = (int) floor($target['max_hp'] / 2);
                        $target['hp'] = max(1, $revivedHp);
                        $target['alive'] = true;
                        $events[] = ['type' => 'revive', 'actor' => $actorId, 'target' => $targetId, 'hp' => $target['hp']];
                        $state['log'][] = "{$target['name']}は蘇生した";
                    } else {
                        $events[] = ['type' => 'revive_failed', 'actor' => $actorId, 'target' => $targetId];
                        $state['log'][] = "{$label}は効果がなかった";
                    }
                }
                break;

            case 'heal':
                $amount = (int) ($spell['power'] ?? 30);
                $healed = min($amount, $target['max_hp'] - $target['hp']);
                $target['hp'] += $healed;
                $events[] = ['type' => 'heal', 'actor' => $actorId, 'target' => $targetId, 'value' => $healed];
                $state['log'][] = "{$target['name']}は{$healed}回復した";
                break;

            case 'buff':
            case 'debuff':
                $buffKey = $spell['buff'];
                $target['buffs'][$buffKey] = config("battle.buffs.{$buffKey}.turns");
                $events[] = [
                    'type' => 'buff_applied',
                    'actor' => $actorId,
                    'target' => $targetId,
                    'buff' => $buffKey,
                    'icon' => config("battle.buffs.{$buffKey}.icon"),
                ];
                $state['log'][] = "{$target['name']}に{$label}";
                break;

            case 'cure_status':
                $target['statuses'] = [];
                $events[] = ['type' => 'status_cured', 'target' => $targetId];
                $state['log'][] = "{$target['name']}の状態異常が治った";
                break;
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolvePhysicalHit(
        array &$state,
        string $actorId,
        string $targetId,
        float $coefficient,
        ?string $element,
        bool $checkEvasion,
    ): array {
        $actor = $state['units'][$actorId];
        $target = $state['units'][$targetId];

        if ($checkEvasion && $this->rollEvasion($target)) {
            $state['log'][] = "{$target['name']}は攻撃を回避した";
            return [['type' => 'miss', 'actor' => $actorId, 'target' => $targetId]];
        }

        $damageResult = $this->calcDamageWithCritical($actor, $target, 'str', $coefficient);

        return $this->applyDamage(
            $state,
            $actorId,
            $targetId,
            $damageResult['damage'],
            $element,
            $damageResult['critical'],
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function applyDamage(
        array &$state,
        string $actorId,
        string $targetId,
        int $damage,
        ?string $element,
        bool $critical = false,
    ): array {
        $target = &$state['units'][$targetId];

        if ($target['damage_shield'] ?? false) {
            $target['damage_shield'] = false;
            $state['log'][] = "{$target['name']}はダメージを無効化した";

            return [
                ['type' => 'shield_break', 'actor' => $actorId, 'target' => $targetId],
            ];
        }

        $target['hp'] = max(0, $target['hp'] - $damage);
        $events = [
            [
                'type' => 'damage',
                'actor' => $actorId,
                'target' => $targetId,
                'value' => $damage,
                'element' => $element,
                'critical' => $critical,
            ],
        ];

        if ($target['hp'] === 0) {
            $target['alive'] = false;
            if (($target['side'] ?? '') === 'enemy') {
                if (! isset($state['meta'])) {
                    $state['meta'] = [];
                }
                $gold = (int) ($target['gold_reward'] ?? 0);
                if ($gold > 0) {
                    $state['meta']['gold_earned'] = (int) ($state['meta']['gold_earned'] ?? 0) + $gold;
                    $events[] = ['type' => 'gold_gained', 'amount' => $gold, 'total' => $state['meta']['gold_earned']];
                }
                $exp = (int) ($target['exp_reward'] ?? 0);
                if ($exp > 0) {
                    $state['meta']['exp_earned'] = (int) ($state['meta']['exp_earned'] ?? 0) + $exp;
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $defender
     * @return array{damage: int, critical: bool}
     */
    private function calcDamageWithCritical(array $attacker, array $defender, string $attackStat, float $coefficient): array
    {
        $critical = $this->rollCritical($attacker);
        $defenderForCalc = $defender;
        if ($critical) {
            $defenderForCalc = [...$defender, 'def' => 0, 'buffs' => []];
        }

        $damage = $this->calcDamage($attacker, $defenderForCalc, $attackStat, $coefficient);

        return ['damage' => $damage, 'critical' => $critical];
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $defender
     */
    private function calcDamage(array $attacker, array $defender, string $attackStat, float $coefficient): int
    {
        $attack = $this->effectiveStat($attacker, $attackStat);
        $def = $this->effectiveStat($defender, 'def');
        $roll = $this->rollDamageMultiplier();
        $raw = ($attack - ($def / 2)) * $roll * $coefficient;

        return (int) max(1, floor($raw));
    }

    private function rollDamageMultiplier(): float
    {
        if ($this->damageRoll !== null) {
            return ($this->damageRoll)();
        }

        return random_int(90, 110) / 100;
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function effectiveStat(array $unit, string $stat): float
    {
        $base = (float) ($unit[$stat] ?? 0);

        foreach ($unit['buffs'] as $key => $turns) {
            if ($turns <= 0) {
                continue;
            }
            $buff = config("battle.buffs.{$key}");
            if (($buff['type'] ?? '') === 'evasion_set') {
                continue;
            }
            if (($buff['stat'] ?? '') === $stat) {
                $base *= (float) $buff['multiplier'];
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $defender
     */
    private function effectiveEvasionRate(array $defender): int
    {
        foreach ($defender['buffs'] as $key => $turns) {
            if ($turns <= 0) {
                continue;
            }
            $buff = config("battle.buffs.{$key}");
            if (($buff['type'] ?? '') === 'evasion_set') {
                return (int) ($buff['value'] ?? 50);
            }
        }

        return (int) ($defender['evasion_rate'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $defender
     */
    private function rollEvasion(array $defender): bool
    {
        $rate = $this->effectiveEvasionRate($defender);

        return $this->rollPercent($this->hitRoll) <= $rate;
    }

    /**
     * @param  array<string, mixed>  $attacker
     */
    private function rollCritical(array $attacker): bool
    {
        $rate = (int) ($attacker['crit_rate'] ?? 0);

        return $this->rollPercent($this->critRoll) <= $rate;
    }

    private function rollHit(int $rate): bool
    {
        return $this->rollPercent($this->hitRoll) <= $rate;
    }

    /**
     * @param  (callable(): int)|null  $roller
     */
    private function rollPercent(?callable $roller = null): int
    {
        if ($roller !== null) {
            return $roller();
        }

        if ($this->hitRoll !== null) {
            return ($this->hitRoll)();
        }

        return random_int(1, 100);
    }

    /**
     * @return array{type: string, actor: string, text: string, spell_element?: string, spell_effect?: string}
     */
    private function announceEvent(
        string $actorId,
        string $text,
        ?string $spellElement = null,
        ?string $spellEffect = null,
    ): array {
        $event = ['type' => 'announce', 'actor' => $actorId, 'text' => $text];
        if ($spellElement !== null && $spellElement !== '') {
            $event['spell_element'] = $spellElement;
        }
        if ($spellEffect !== null && $spellEffect !== '') {
            $event['spell_effect'] = $spellEffect;
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function resolveSpellTargets(array $spell, ?string $targetId, array $units, string $actorSide): array
    {
        if ($spell['effect'] === 'revive_chance') {
            if ($targetId === null || ! isset($units[$targetId])) {
                throw new InvalidArgumentException('ターゲットを指定してください。');
            }

            return [$targetId];
        }

        return $this->resolveTargets($spell['target_type'], $targetId, $units, $actorSide);
    }

    /**
     * 敵特技の target_type（ally_*）はプレイヤー味方を指す。
     *
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function resolveEnemySkillTargets(string $targetType, array $units): array
    {
        return match ($targetType) {
            'ally_all' => array_keys(array_filter($units, fn ($u) => $u['side'] === 'ally' && $u['alive'])),
            default => throw new InvalidArgumentException("不明な敵ターゲット種別です: {$targetType}"),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function resolveTargets(string $targetType, ?string $targetId, array $units, string $actorSide): array
    {
        return match ($targetType) {
            'self' => [],
            'enemy_single', 'ally_single' => $targetId ? [$targetId] : throw new InvalidArgumentException('ターゲットを指定してください。'),
            'enemy_all' => array_keys(array_filter($units, fn ($u) => $u['side'] !== $actorSide && $u['alive'])),
            'ally_all' => array_keys(array_filter($units, fn ($u) => $u['side'] === $actorSide && $u['alive'])),
            default => throw new InvalidArgumentException('不明なターゲット種別です。'),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     */
    private function assertTarget(?string $targetId, string $expectedType, array $units): void
    {
        if ($targetId === null || ! isset($units[$targetId])) {
            throw new InvalidArgumentException('有効なターゲットを指定してください。');
        }

        if ($expectedType === 'ally_single' && ! $units[$targetId]['alive']) {
            throw new InvalidArgumentException('有効なターゲットを指定してください。');
        }

        if ($expectedType === 'enemy_single' && ! $units[$targetId]['alive']) {
            throw new InvalidArgumentException('有効なターゲットを指定してください。');
        }

        $side = $units[$targetId]['side'];
        if ($expectedType === 'enemy_single' && $side !== 'enemy') {
            throw new InvalidArgumentException('敵を指定してください。');
        }
        if ($expectedType === 'ally_single' && $side !== 'ally') {
            throw new InvalidArgumentException('味方を指定してください。');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function nextTurnIndex(array $state, string $currentActor): int
    {
        $order = $state['turn_order'];
        $current = array_search($currentActor, $order, true);
        $count = count($order);

        for ($i = 1; $i <= $count; $i++) {
            return ($current + $i) % $count;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function checkBattleEnd(array $state): ?string
    {
        $alliesAlive = false;
        $enemiesAlive = false;

        foreach ($state['units'] as $unit) {
            if (! $unit['alive']) {
                continue;
            }
            if ($unit['side'] === 'ally') {
                $alliesAlive = true;
            } else {
                $enemiesAlive = true;
            }
        }

        if (! $enemiesAlive) {
            return 'victory';
        }
        if (! $alliesAlive) {
            return 'defeat';
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     */
    private function pickRandomAliveAlly(array $units): ?string
    {
        $ids = array_keys(array_filter($units, fn ($u) => $u['side'] === 'ally' && $u['alive']));
        if ($ids === []) {
            return null;
        }

        if ($this->enemyTargetPicker !== null) {
            return ($this->enemyTargetPicker)($units);
        }

        return $ids[array_rand($ids)];
    }

    /**
     * @param  array<string, mixed>  $unit
     */
    private function tickBuffsForUnit(array &$unit): void
    {
        foreach ($unit['buffs'] as $key => $turns) {
            if ($turns <= 1) {
                unset($unit['buffs'][$key]);
            } else {
                $unit['buffs'][$key] = $turns - 1;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function publicState(array $state): array
    {
        $actor = $state['current_actor'] ?? null;
        $unit = $actor ? ($state['units'][$actor] ?? null) : null;

        $buffIcons = [];
        foreach (config('battle.buffs', []) as $key => $buff) {
            $buffIcons[$key] = $buff['icon'] ?? $key;
        }

        return [
            'status' => $state['status'],
            'current_actor' => $actor,
            'awaiting_input' => $state['awaiting_input'] ?? false,
            'turn_index' => $state['turn_index'] ?? 0,
            'meta' => $state['meta'] ?? null,
            'units' => array_values(array_map(
                fn (array $u) => $this->publicUnit($u),
                $state['units'],
            )),
            'log' => array_slice($state['log'] ?? [], -8),
            'commands' => $this->availableCommands($unit, $state['units'] ?? []),
            'buff_icons' => $buffIcons,
        ];
    }

    /**
     * @param  array<string, mixed>  $unit
     * @return array<string, mixed>
     */
    private function publicUnit(array $unit): array
    {
        $buffs = [];
        foreach ($unit['buffs'] as $key => $turns) {
            if ($turns > 0) {
                $buffs[$key] = $turns;
            }
        }

        return [
            'id' => $unit['id'],
            'side' => $unit['side'],
            'name' => $unit['name'],
            'sprite' => $unit['sprite'],
            'master_code' => $unit['master_code'] ?? null,
            'level' => $unit['level'] ?? 1,
            'hp' => $unit['hp'],
            'max_hp' => $unit['max_hp'],
            'mp' => $unit['mp'],
            'max_mp' => $unit['max_mp'],
            'alive' => $unit['alive'],
            'buffs' => $buffs,
            'damage_shield' => (bool) ($unit['damage_shield'] ?? false),
            'user_character_id' => $unit['user_character_id'] ?? null,
            'weapon' => $unit['weapon'] ?? null,
            'armor' => $unit['armor'] ?? null,
            'str' => $unit['str'] ?? null,
            'mag' => $unit['mag'] ?? null,
            'def' => $unit['def'] ?? null,
            'spd' => $unit['spd'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $unit
     * @param  array<string, array<string, mixed>>  $units
     * @return array<string, mixed>|null
     */
    private function availableCommands(?array $unit, array $units = []): ?array
    {
        if ($unit === null || ! str_starts_with($unit['id'], 'pc')) {
            return null;
        }

        $spells = [];
        foreach ($unit['spells'] as $spellId) {
            $spell = $this->factory->findSpell($spellId);
            $affordable = $unit['mp'] >= $spell['mp_cost'];
            $usable = $affordable && $this->spellHasValidTarget($spell, $units);
            $spells[] = [
                'id' => $spellId,
                'label' => $spell['label'],
                'description' => $spell['description'] ?? '',
                'mp_cost' => $spell['mp_cost'],
                'target_type' => $spell['target_type'],
                'effect' => $spell['effect'],
                'affordable' => $affordable,
                'usable' => $usable,
            ];
        }

        return [
            'punch' => config('battle.common_actions.punch'),
            'kick' => config('battle.common_actions.kick'),
            'spells' => $spells,
        ];
    }
}
