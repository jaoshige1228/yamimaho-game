<?php

namespace App\Services\Battle;

use InvalidArgumentException;

class BattleEngine
{
    /** @var (callable(array<string, array<string, mixed>>): ?string)|null */
    private $enemyTargetPicker = null;

    /** @var (callable(): float)|null */
    private $damageRoll = null;

    /**
     * @param  (callable(array<string, array<string, mixed>>): ?string)|null  $enemyTargetPicker
     *        テスト・シミュレーション用。null のときはランダムに味方を選択。
     * @param  (callable(): float)|null  $damageRoll
     *        テスト用。0.9〜1.1 の乱数を返す。null のときは random_int(90,110)/100。
     */
    public function __construct(
        private readonly BattleFactory $factory = new BattleFactory,
        ?callable $enemyTargetPicker = null,
        ?callable $damageRoll = null,
    ) {
        $this->enemyTargetPicker = $enemyTargetPicker;
        $this->damageRoll = $damageRoll;
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

        if ($action !== 'defend') {
            $state['units'][$actorId]['defending'] = false;
        } else {
            $state['units'][$actorId]['defending'] = true;
        }
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

            $events = [['type' => 'turn_start', 'actor' => $actor]];
            $target = $this->pickRandomAliveAlly($state['units']);
            if ($target === null) {
                break;
            }

            $events = array_merge($events, $this->resolveAction($state, $actor, 'punch', null, $target));
            $state['units'][$actor]['defending'] = false;
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

        // 防御は敵フェーズを跨いで有効。同 PC の次の手番が来たときだけ解除する。
        if ($nextActor !== null && str_starts_with($nextActor, 'pc')) {
            $state['units'][$nextActor]['defending'] = false;
        }

        return ['state' => $state, 'events' => $events];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolveAction(array &$state, string $actorId, string $action, ?string $spellId, ?string $targetId): array
    {
        $actor = &$state['units'][$actorId];
        $events = [];

        if ($action === 'defend') {
            $state['log'][] = "{$actor['name']}は防御の構えをとった。";
            $events[] = ['type' => 'defend', 'actor' => $actorId];

            return $events;
        }

        if ($action === 'punch') {
            $targetSide = str_starts_with($actorId, 'enemy') ? 'ally_single' : 'enemy_single';
            $this->assertTarget($targetId, $targetSide, $state['units']);
            $coefficient = (float) config('battle.common_actions.punch.coefficient', 1.0);
            $damage = $this->calcDamage($actor, $state['units'][$targetId], 'str', $coefficient);
            $events = array_merge($events, $this->applyDamage($state, $actorId, $targetId, $damage, null));
            $state['log'][] = "{$actor['name']}のこぶし！ {$state['units'][$targetId]['name']}に{$damage}ダメージ";

            return $events;
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

        $actor['mp'] -= $mpCost;
        $events[] = ['type' => 'mp_spent', 'actor' => $actorId, 'amount' => $mpCost];

        $targets = $this->resolveTargets($spell['target_type'], $targetId, $state['units'], $actor['side']);

        foreach ($targets as $tid) {
            $events = array_merge($events, $this->applySpellEffect($state, $actorId, $tid, $spell, $spellId));
        }

        return $events;
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
                $damage = $this->calcDamage($actor, $target, 'mag', $coefficient);
                $events = array_merge($events, $this->applyDamage($state, $actorId, $targetId, $damage, $spell['element'] ?? null));
                $state['log'][] = "{$actor['name']}の{$label}！ {$target['name']}に{$damage}ダメージ";
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
                $events[] = ['type' => 'buff_applied', 'actor' => $actorId, 'target' => $targetId, 'buff' => $buffKey];
                $state['log'][] = "{$target['name']}に{$label}";
                break;

            case 'cure_status':
                $target['statuses'] = [];
                $events[] = ['type' => 'status_cured', 'target' => $targetId];
                $state['log'][] = "{$target['name']}の状態異常が治った";
                break;
        }

        $events[] = ['type' => 'spell_cast', 'actor' => $actorId, 'spell_id' => $spellId, 'target' => $targetId, 'element' => $spell['element'] ?? null];

        return $events;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function applyDamage(array &$state, string $actorId, string $targetId, int $damage, ?string $element): array
    {
        $target = &$state['units'][$targetId];
        $target['hp'] = max(0, $target['hp'] - $damage);
        if ($target['hp'] === 0) {
            $target['alive'] = false;
        }

        return [
            ['type' => 'damage', 'actor' => $actorId, 'target' => $targetId, 'value' => $damage, 'element' => $element],
        ];
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

        return $this->finalizeDamage($raw, $defender);
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
        $base = match ($stat) {
            'evasion' => (float) $unit['spd'],
            default => (float) ($unit[$stat] ?? 0),
        };

        foreach ($unit['buffs'] as $key => $turns) {
            if ($turns <= 0) {
                continue;
            }
            $buff = config("battle.buffs.{$key}");
            if (($buff['stat'] ?? '') === $stat) {
                $base *= (float) $buff['multiplier'];
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $defender
     */
    private function finalizeDamage(float $raw, array $defender): int
    {
        $damage = (int) max(1, floor($raw));
        if ($defender['defending'] ?? false) {
            $damage = (int) max(1, floor($damage * (float) config('battle.defend_damage_multiplier')));
        }

        return $damage;
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
        if ($targetId === null || ! isset($units[$targetId]) || ! $units[$targetId]['alive']) {
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
            $idx = ($current + $i) % $count;

            return $idx;
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

        return [
            'status' => $state['status'],
            'current_actor' => $actor,
            'awaiting_input' => $state['awaiting_input'] ?? false,
            'turn_index' => $state['turn_index'] ?? 0,
            'units' => array_values($state['units']),
            'log' => array_slice($state['log'] ?? [], -8),
            'commands' => $this->availableCommands($unit),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $unit
     * @return array<string, mixed>|null
     */
    private function availableCommands(?array $unit): ?array
    {
        if ($unit === null || ! str_starts_with($unit['id'], 'pc')) {
            return null;
        }

        $spells = [];
        foreach ($unit['spells'] as $spellId) {
            $spell = $this->factory->findSpell($spellId);
            $spells[] = [
                'id' => $spellId,
                'label' => $spell['label'],
                'description' => $spell['description'] ?? '',
                'mp_cost' => $spell['mp_cost'],
                'target_type' => $spell['target_type'],
                'affordable' => $unit['mp'] >= $spell['mp_cost'],
            ];
        }

        return [
            'punch' => config('battle.common_actions.punch'),
            'defend' => config('battle.common_actions.defend'),
            'spells' => $spells,
        ];
    }
}
