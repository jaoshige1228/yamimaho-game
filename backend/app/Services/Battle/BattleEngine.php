<?php

namespace App\Services\Battle;

use InvalidArgumentException;

class BattleEngine
{
    /** HPを回復する魔法の effect（heal_mag = 魔力×係数、heal = 固定値、heal_full = 全回復） */
    private const HEAL_EFFECTS = ['heal_mag', 'heal', 'heal_full'];

    /** 戦闘不能の味方を蘇生する魔法の effect（revive_chance = 確率、revive_full = 確実にHP全回復） */
    private const REVIVE_EFFECTS = ['revive_chance', 'revive_full'];

    /** @var (callable(array<string, array<string, mixed>>): ?string)|null */
    private $enemyTargetPicker = null;

    /** @var (callable(): float)|null */
    private $damageRoll = null;

    /** @var (callable(): int)|null */
    private $hitRoll = null;

    /** @var (callable(): int)|null */
    private $critRoll = null;

    /** 使い魔の支援の判断（発動するか・何をするか・誰にか）。効果の適用はこのクラスが行う */
    private FamiliarSupport $familiarSupport;

    /**
     * @param  (callable(array<string, array<string, mixed>>): ?string)|null  $enemyTargetPicker
     * @param  (callable(): float)|null  $damageRoll
     * @param  (callable(): int)|null  $hitRoll  1〜100
     * @param  (callable(): int)|null  $critRoll  1〜100
     * @param  (callable(int, int): int)|null  $familiarRoll  使い魔の支援の乱数（min〜max の整数。確率・行動・対象・顔の選択すべてに使う）
     */
    public function __construct(
        private readonly BattleFactory $factory = new BattleFactory,
        private readonly EnemySkillPicker $enemySkillPicker = new EnemySkillPicker,
        ?callable $enemyTargetPicker = null,
        ?callable $damageRoll = null,
        ?callable $hitRoll = null,
        ?callable $critRoll = null,
        ?callable $familiarRoll = null,
    ) {
        $this->enemyTargetPicker = $enemyTargetPicker;
        $this->damageRoll = $damageRoll;
        $this->hitRoll = $hitRoll;
        $this->critRoll = $critRoll;
        $this->familiarSupport = new FamiliarSupport($familiarRoll);
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
            return $this->endBattle($state, $events, $end);
        }

        $nextIndex = $this->nextTurnIndex($state, $actorId);
        $nextActor = $this->factory->findNextActor($state['units'], $state['turn_order'], $nextIndex);

        // 全員（味方と敵）の行動が一周したら、使い魔の支援の抽選を行う
        if ($this->roundCompleted($state, $actorId, $nextActor)) {
            $support = $this->runFamiliarSupport($state);
            if ($support !== []) {
                $events = array_merge($events, $support);

                // 敵を倒しきった（勝利）・蘇生で手番が変わった、などを反映する
                $end = $this->checkBattleEnd($state);
                if ($end !== null) {
                    return $this->endBattle($state, $events, $end);
                }
                $nextActor = $this->factory->findNextActor($state['units'], $state['turn_order'], $nextIndex);
            }
        }

        $state['turn_index'] = $nextIndex;
        $state['current_actor'] = $nextActor;
        $state['awaiting_input'] = $nextActor !== null && str_starts_with($nextActor, 'pc');

        return ['state' => $state, 'events' => $events];
    }

    /**
     * 戦闘の終了処理（勝敗の確定）。
     *
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $events
     * @return array{state: array<string, mixed>, events: list<array<string, mixed>>}
     */
    private function endBattle(array $state, array $events, string $end): array
    {
        $state['status'] = $end;
        $state['awaiting_input'] = false;
        $state['current_actor'] = null;
        $events[] = ['type' => 'battle_end', 'result' => $end];
        $state['log'][] = $end === 'victory' ? '勝利！' : '敗北…';

        return ['state' => $state, 'events' => $events];
    }

    /**
     * 今の行動で、全員の行動が一周したか（次に動くユニットの位置が、今のユニット以前に戻ったか）。
     * 戦闘不能・逃走したユニットは飛ばされるので、生の turn_index ではなく turn_order 上の位置で比べる。
     *
     * @param  array<string, mixed>  $state
     */
    private function roundCompleted(array $state, string $actorId, ?string $nextActor): bool
    {
        if ($nextActor === null) {
            return false;
        }

        $order = array_values($state['turn_order']);
        $current = array_search($actorId, $order, true);
        $next = array_search($nextActor, $order, true);

        return $current !== false && $next !== false && $next <= $current;
    }

    /**
     * 使い魔の支援（抽選 → 行動の決定 → 効果の適用）。戦闘に使い魔が設定されていなければ何もしない。
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function runFamiliarSupport(array &$state): array
    {
        $level = $this->familiarSupport->levelOf($state);
        if ($level < 1 || ! $this->familiarSupport->shouldTrigger($level)) {
            return [];
        }

        $decision = $this->familiarSupport->decide($state['units'], $level);
        if ($decision === null) {
            return [];
        }

        return $this->applyFamiliarSupport($state, $level, $decision);
    }

    /**
     * 決まった支援の演出イベントと、効果の適用。効果のイベントは通常の damage / heal / buff_applied / revive と同じ形で、
     * actor は 'familiar'。
     *
     * @param  array<string, mixed>  $state
     * @param  array{action: string, kind: string, targets: list<string>, portrait: string}  $decision
     * @return list<array<string, mixed>>
     */
    private function applyFamiliarSupport(array &$state, int $level, array $decision): array
    {
        $action = (array) config("familiar.actions.{$decision['action']}");
        $targetName = isset($decision['targets'][0]) ? (string) ($state['units'][$decision['targets'][0]]['name'] ?? '') : '';
        $message = str_replace('{target}', $targetName, (string) $action['message']);
        $title = (string) config('familiar.support_title');

        $events = [
            ['type' => 'familiar_support', 'portrait' => $decision['portrait'], 'text' => $title],
            ['type' => 'familiar_action', 'portrait' => $decision['portrait'], 'text' => $message],
        ];
        $state['log'][] = $title;
        $state['log'][] = $message;

        $averageMag = $this->familiarSupport->averageMag($state['units']);

        foreach ($decision['targets'] as $targetId) {
            $target = &$state['units'][$targetId];

            switch ($decision['kind']) {
                case 'damage':
                    $coefficient = $this->familiarSupport->coefficientFor($decision['action'], $level);
                    $damage = $this->calcDamage(['mag' => $averageMag, 'buffs' => []], $target, 'mag', $coefficient, 'magical');
                    $targetLabel = (string) $target['name'];
                    unset($target);
                    $events = array_merge($events, $this->applyDamage($state, 'familiar', $targetId, $damage, null, false));
                    $state['log'][] = "{$targetLabel}に{$damage}ダメージ";
                    break;

                case 'heal':
                    // 満タン・戦闘不能の味方は回復しない（全体回復で混ざる）
                    if (! $target['alive'] || $target['hp'] >= $target['max_hp']) {
                        break;
                    }
                    $coefficient = $this->familiarSupport->coefficientFor($decision['action'], $level);
                    $amount = SpellHealing::healMagAmount($averageMag, ['coefficient' => $coefficient]);
                    $healed = min($amount, $target['max_hp'] - $target['hp']);
                    $target['hp'] += $healed;
                    $events[] = ['type' => 'heal', 'actor' => 'familiar', 'target' => $targetId, 'value' => $healed];
                    $state['log'][] = "{$target['name']}は{$healed}回復した";
                    break;

                case 'buff':
                    $buffKey = $this->familiarSupport->attackBuffKey($level);
                    // 強化版と通常版は重ねない
                    unset($target['buffs']['atk_up_familiar'], $target['buffs']['atk_up_familiar_large']);
                    $target['buffs'][$buffKey] = config("battle.buffs.{$buffKey}.turns");
                    $events[] = [
                        'type' => 'buff_applied',
                        'actor' => 'familiar',
                        'target' => $targetId,
                        'buff' => $buffKey,
                        'icon' => config("battle.buffs.{$buffKey}.icon"),
                    ];
                    break;

                case 'revive':
                    if ($target['alive']) {
                        break;
                    }
                    $hp = max(1, (int) floor($target['max_hp'] * (int) config('familiar.revive_hp_percent') / 100));
                    $target['hp'] = min($hp, (int) $target['max_hp']);
                    $target['alive'] = true;
                    $events[] = ['type' => 'revive', 'actor' => 'familiar', 'target' => $targetId, 'hp' => $target['hp']];
                    $state['log'][] = "{$target['name']}は蘇生した";
                    break;
            }
            unset($target);
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $skill
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolveEnemySkill(array &$state, string $actorId, array $skill): array
    {
        $actor = $state['units'][$actorId];
        $skill = $this->swapUselessEnemySupport($state['units'], $skill);
        $announceText = $skill['skill_code'] === 'attack'
            ? "{$actor['name']}の攻撃！"
            : "{$actor['name']}の{$skill['label']}！";

        $targetType = (string) $skill['target_type'];
        $coefficient = (float) $skill['coefficient'];
        $actionType = (string) $skill['action_type'];

        if ($actionType === 'flee') {
            return $this->resolveEnemyFlee($state, $actorId);
        }

        // 魔法は「〜は〜を唱えた！」だけを告知する（技名の告知と重なるため出さない）
        $events = $actionType === 'spell' ? [] : [$this->announceEvent($actorId, $announceText)];

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

        if ($actionType === 'spell') {
            $spellId = (string) $skill['skill_code'];
            $spell = $this->factory->findSpell($spellId);
            $targets = $this->resolveEnemySpellTargets($spell, $state['units'], $actorId);

            // 対象がなく不発でも、詠唱した事実は告知する（無言のターンにしない）
            $events[] = $this->announceEvent(
                $actorId,
                $this->spellAnnounceText($actor['name'], $spell),
                isset($spell['element']) && $spell['element'] !== '' ? (string) $spell['element'] : null,
                isset($spell['effect']) && $spell['effect'] !== '' ? (string) $spell['effect'] : null,
            );

            foreach ($targets as $tid) {
                $events = array_merge($events, $this->applySpellEffect($state, $actorId, $tid, $spell, $spellId));
            }
            $events = array_merge($events, $this->applySpellSelfEffects($state, $actorId, $spell));

            return $events;
        }

        throw new InvalidArgumentException("不明な敵行動です: {$actionType}");
    }

    /**
     * 回復する味方がいない（傷ついた味方がいない）、蘇生する味方がいない（倒れた味方がいない）のに
     * その魔法を引いたときは、空振りさせず通常攻撃にする。
     *
     * @param  array<string, array<string, mixed>>  $units
     * @param  array<string, mixed>  $skill
     * @return array<string, mixed>
     */
    private function swapUselessEnemySupport(array $units, array $skill): array
    {
        if (($skill['action_type'] ?? '') !== 'spell') {
            return $skill;
        }

        $spell = $this->factory->findSpell((string) $skill['skill_code']);
        $useless = match ((string) ($spell['effect'] ?? '')) {
            'heal_mag', 'heal_full' => $this->woundedEnemyIds($units) === [],
            'revive_chance', 'revive_full' => $this->fallenEnemyIds($units) === [],
            default => false,
        };
        if (! $useless) {
            return $skill;
        }

        return [
            'skill_code' => 'attack',
            'label' => '攻撃',
            'action_type' => 'punch',
            'target_type' => 'ally_single',
            'coefficient' => 1.0,
        ];
    }

    /**
     * HP が減っている生存中の敵ユニット。
     *
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function woundedEnemyIds(array $units): array
    {
        return array_keys(array_filter(
            $units,
            fn ($u) => ($u['side'] ?? '') === 'enemy' && ($u['alive'] ?? false) && $u['hp'] < $u['max_hp'],
        ));
    }

    /**
     * 倒れている敵ユニット（逃げ出した敵は含まない。蘇生の対象）。
     *
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function fallenEnemyIds(array $units): array
    {
        return array_keys(array_filter(
            $units,
            fn ($u) => ($u['side'] ?? '') === 'enemy' && ! ($u['alive'] ?? true) && ! ($u['fled'] ?? false),
        ));
    }

    /**
     * 敵が戦場から逃げる。倒したわけではないので、経験値・ゴールドは入らない。
     * 戦闘不能と同じ扱い（alive = false）にするので、敵が全員いなくなれば通常どおり勝利になる。
     *
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function resolveEnemyFlee(array &$state, string $actorId): array
    {
        $unit = &$state['units'][$actorId];
        $unit['alive'] = false;
        $unit['fled'] = true;
        $state['log'][] = "{$unit['name']}は逃げ出した！";

        return [
            $this->announceEvent($actorId, "{$unit['name']}は逃げ出した！"),
            ['type' => 'enemy_fled', 'actor' => $actorId],
        ];
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
            $coefficient = $this->physicalCoefficient($actor, 'punch');
            $events[] = $this->announceEvent($actorId, "{$actor['name']}のこぶし！");

            return array_merge(
                $events,
                $this->resolvePhysicalHit($state, $actorId, (string) $targetId, $coefficient, null, true),
            );
        }

        if ($action === 'kick') {
            $this->assertTarget($targetId, 'enemy_single', $state['units']);
            $coefficient = $this->physicalCoefficient($actor, 'kick');
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
        if (($spell['target_type'] ?? '') === 'ally_all' && in_array($spell['effect'] ?? '', self::HEAL_EFFECTS, true)) {
            // 味方全体の回復は、傷ついている味方が一人でもいれば使える（満タンの味方は回復しないだけ）
            $anyWounded = false;
            foreach ($targets as $tid) {
                if ((int) $state['units'][$tid]['hp'] < (int) $state['units'][$tid]['max_hp']) {
                    $anyWounded = true;
                }
            }
            if (! $anyWounded) {
                throw new InvalidArgumentException('回復できない対象です。');
            }
        } else {
            foreach ($targets as $tid) {
                $this->assertSpellTargetValid($spell, $state['units'][$tid]);
            }
        }

        $actor['mp'] -= $mpCost;
        $events[] = ['type' => 'mp_spent', 'actor' => $actorId, 'amount' => $mpCost];
        $events[] = $this->announceEvent(
            $actorId,
            $this->spellAnnounceText($actor['name'], $spell),
            isset($spell['element']) && $spell['element'] !== '' ? (string) $spell['element'] : null,
            isset($spell['effect']) && $spell['effect'] !== '' ? (string) $spell['effect'] : null,
        );

        foreach ($targets as $tid) {
            $events = array_merge($events, $this->applySpellEffect($state, $actorId, $tid, $spell, $spellId));
        }
        $events = array_merge($events, $this->applySpellSelfEffects($state, $actorId, $spell));

        return $events;
    }

    /**
     * @param  array<string, mixed>  $spell
     * @param  array<string, mixed>  $target
     */
    private function assertSpellTargetValid(array $spell, array $target): void
    {
        $effect = $spell['effect'] ?? '';

        if (in_array($effect, self::HEAL_EFFECTS, true)) {
            if (! ($target['alive'] ?? false) || (int) $target['hp'] >= (int) $target['max_hp']) {
                throw new InvalidArgumentException('回復できない対象です。');
            }

            return;
        }

        if (in_array($effect, self::REVIVE_EFFECTS, true)) {
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

            if (in_array($effect, self::HEAL_EFFECTS, true)) {
                if (($unit['alive'] ?? false) && (int) $unit['hp'] < (int) $unit['max_hp']) {
                    return true;
                }

                continue;
            }

            if (in_array($effect, self::REVIVE_EFFECTS, true)) {
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

        // ダメージを与える魔法（即死を含む）は回避判定の対象（判定するかどうかは shouldRollEvasion を参照）
        if (in_array($spell['effect'], ['damage', 'damage_hp_cost', 'instant_death'], true)
            && ($target['alive'] ?? false)
            && $this->shouldRollEvasion($actor, $target, false)
            && $this->rollEvasion($target)) {
            $state['log'][] = "{$target['name']}は{$label}を回避した";

            return [['type' => 'miss', 'actor' => $actorId, 'target' => $targetId]];
        }

        switch ($spell['effect']) {
            case 'damage':
            case 'damage_hp_cost':
                $coefficient = (float) ($spell['coefficient'] ?? 1.0);
                $damageResult = $this->calcDamageWithCritical($actor, $target, 'mag', $coefficient, 'magical');
                $events = array_merge(
                    $events,
                    $this->applyDamage($state, $actorId, $targetId, $damageResult['damage'], $spell['element'] ?? null, $damageResult['critical']),
                );
                $state['log'][] = "{$actor['name']}の{$label}！ {$target['name']}に{$damageResult['damage']}ダメージ";
                break;

            case 'heal_mag':
                // 戦闘不能・HP満タンの対象は回復しない（味方全体の回復で混ざることがある。倒れた人は蘇生でしか戻らない）
                if (! $target['alive'] || $target['hp'] >= $target['max_hp']) {
                    break;
                }
                $amount = SpellHealing::healMagAmount((float) $actor['mag'], $spell);
                $healed = min($amount, $target['max_hp'] - $target['hp']);
                $target['hp'] += $healed;
                $events[] = ['type' => 'heal', 'actor' => $actorId, 'target' => $targetId, 'value' => $healed];
                $state['log'][] = "{$target['name']}は{$healed}回復した";
                break;

            case 'heal_full':
                if (! $target['alive'] || $target['hp'] >= $target['max_hp']) {
                    break;
                }
                $healed = $target['max_hp'] - $target['hp'];
                $target['hp'] = $target['max_hp'];
                $events[] = ['type' => 'heal', 'actor' => $actorId, 'target' => $targetId, 'value' => $healed];
                $state['log'][] = "{$target['name']}のHPが全回復した";
                break;

            case 'revive_full':
                if (! $target['alive']) {
                    $target['hp'] = $target['max_hp'];
                    $target['alive'] = true;
                    $events[] = ['type' => 'revive', 'actor' => $actorId, 'target' => $targetId, 'hp' => $target['hp']];
                    $state['log'][] = "{$target['name']}はHP全回復で蘇生した";
                }
                break;

            case 'life_ward':
                if (! $target['alive']) {
                    break;
                }
                $target['life_ward'] = true;
                $events[] = ['type' => 'life_ward_applied', 'actor' => $actorId, 'target' => $targetId];
                $state['log'][] = "{$target['name']}に{$label}";
                break;

            case 'buff_evasion_set':
            case 'buff_def':
            case 'debuff_def':
            case 'debuff_def_both':
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

            case 'instant_death':
                if (! $target['alive']) {
                    break;
                }
                // power = 即死の成功率（%）。外れたら何も起きない
                if (! $this->rollHit((int) ($spell['power'] ?? 10))) {
                    $events[] = ['type' => 'spell_failed', 'actor' => $actorId, 'target' => $targetId];
                    $state['log'][] = "{$label}は{$target['name']}に効かなかった";
                    break;
                }
                // 残りHP分のダメージとして扱うので、魔法障壁で防げ、敵が倒れたときの経験値・ゴールドも通常どおり入る
                $deathEvents = $this->applyDamage($state, $actorId, $targetId, (int) $target['hp'], null, false);
                foreach ($deathEvents as $i => $deathEvent) {
                    if (($deathEvent['type'] ?? '') === 'damage') {
                        $deathEvents[$i]['instant_death'] = true;
                    }
                }
                $events = array_merge($events, $deathEvents);
                if (! $target['alive']) {
                    $state['log'][] = "{$target['name']}は{$label}で倒れた";
                }
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
                        $revivedHp = (int) floor($target['max_hp'] * (int) config('battle.revive_hp_percent', 50) / 100);
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
     * 魔法を唱えた本人に、対象の数に関係なく 1 回だけ起きる効果。
     * - damage_hp_cost: 最大 HP の power % を消費する（自滅はせず、HP は最低 1 残る）
     * - debuff_def_both: 対象と同じ防御ダウンを自分にも受ける
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $spell
     * @return list<array<string, mixed>>
     */
    private function applySpellSelfEffects(array &$state, string $actorId, array $spell): array
    {
        $events = [];
        $caster = &$state['units'][$actorId];

        switch ($spell['effect']) {
            case 'damage_hp_cost':
                $cost = (int) floor($caster['max_hp'] * (int) ($spell['power'] ?? 50) / 100);
                $lost = min($cost, max(0, (int) $caster['hp'] - 1));
                if ($lost > 0) {
                    $caster['hp'] -= $lost;
                    $events[] = [
                        'type' => 'damage',
                        'actor' => $actorId,
                        'target' => $actorId,
                        'value' => $lost,
                        'element' => null,
                        'critical' => false,
                    ];
                    $state['log'][] = "{$caster['name']}は反動で{$lost}ダメージを受けた";
                }
                break;

            case 'debuff_def_both':
                $buffKey = $spell['buff'];
                $caster['buffs'][$buffKey] = config("battle.buffs.{$buffKey}.turns");
                $events[] = [
                    'type' => 'buff_applied',
                    'actor' => $actorId,
                    'target' => $actorId,
                    'buff' => $buffKey,
                    'icon' => config("battle.buffs.{$buffKey}.icon"),
                ];
                $state['log'][] = "{$caster['name']}にも{$spell['label']}";
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

        if ($this->shouldRollEvasion($actor, $target, $checkEvasion) && $this->rollEvasion($target)) {
            $state['log'][] = "{$target['name']}は攻撃を回避した";
            return [['type' => 'miss', 'actor' => $actorId, 'target' => $targetId]];
        }

        $damageResult = $this->calcDamageWithCritical($actor, $target, 'str', $coefficient, 'physical');

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

        // 命の加護: 戦闘不能になる攻撃を受けても HP が 1 だけ残る（発動したら加護は消える）
        $wardTriggered = false;
        if (($target['life_ward'] ?? false) && $damage >= $target['hp']) {
            $target['life_ward'] = false;
            $damage = max(0, (int) $target['hp'] - 1);
            $wardTriggered = true;
            $state['log'][] = "{$target['name']}は命の加護で踏みとどまった";
        }

        $target['hp'] = max(0, $target['hp'] - $damage);
        $events = [];
        if (! $wardTriggered || $damage > 0) {
            $events[] = [
                'type' => 'damage',
                'actor' => $actorId,
                'target' => $targetId,
                'value' => $damage,
                'element' => $element,
                'critical' => $critical,
            ];
        }
        if ($wardTriggered) {
            $events[] = ['type' => 'life_ward_triggered', 'actor' => $actorId, 'target' => $targetId];
        }

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
    private function calcDamageWithCritical(
        array $attacker,
        array $defender,
        string $attackStat,
        float $coefficient,
        string $defenseKind = 'physical',
    ): array {
        $critical = $this->rollCritical($attacker);
        $defenderForCalc = $defender;
        if ($critical) {
            $defKey = $this->defenseStatKey($defender, $defenseKind);
            $defenderForCalc = [...$defender, $defKey => 0, 'buffs' => []];
        }

        $damage = $this->calcDamage($attacker, $defenderForCalc, $attackStat, $coefficient, $defenseKind);

        return ['damage' => $damage, 'critical' => $critical];
    }

    /**
     * @param  array<string, mixed>  $defender
     */
    private function defenseStatKey(array $defender, string $defenseKind): string
    {
        if (($defender['side'] ?? '') === 'enemy') {
            return $defenseKind === 'magical' ? 'mdef' : 'pdef';
        }

        return 'def';
    }

    /**
     * 味方 Lv10 以降はこぶし 1.5 / キック 3.0。敵の物理技は従来どおり。
     *
     * @param  array<string, mixed>  $actor
     */
    private function physicalCoefficient(array $actor, string $action): float
    {
        $base = (float) config("battle.common_actions.{$action}.coefficient", $action === 'kick' ? 2.0 : 1.0);
        $side = (string) ($actor['side'] ?? '');
        $id = (string) ($actor['id'] ?? '');
        $isAlly = $side === 'ally' || str_starts_with($id, 'pc');
        if (! $isAlly) {
            return $base;
        }

        $minLevel = (int) config('battle.physical_mastery.min_level', 10);
        $level = (int) ($actor['level'] ?? 1);
        if ($level < $minLevel) {
            return $base;
        }

        $key = $action === 'kick' ? 'kick_coefficient' : 'punch_coefficient';

        return (float) config("battle.physical_mastery.{$key}", $base);
    }

    /**
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $defender
     */
    private function calcDamage(
        array $attacker,
        array $defender,
        string $attackStat,
        float $coefficient,
        string $defenseKind = 'physical',
    ): int {
        $attack = $this->effectiveStat($attacker, $attackStat);
        $defStat = $this->defenseStatKey($defender, $defenseKind);
        $def = $this->effectiveStat($defender, $defStat);
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

        if ($base === 0.0 && in_array($stat, ['pdef', 'mdef'], true)) {
            $base = (float) ($unit['def'] ?? 0);
        }

        foreach ($unit['buffs'] as $key => $turns) {
            if ($turns <= 0) {
                continue;
            }
            $buff = config("battle.buffs.{$key}");
            if (($buff['type'] ?? '') === 'evasion_set') {
                continue;
            }
            // stat は 1 つの文字列、または複数（使い魔の攻撃バフは筋力と魔力の両方）
            $buffStats = (array) ($buff['stat'] ?? '');
            $applies = in_array($stat, $buffStats, true)
                || (in_array('def', $buffStats, true) && in_array($stat, ['def', 'pdef', 'mdef'], true));
            if ($applies) {
                $base *= (float) $buff['multiplier'];
            }
        }

        return $base;
    }

    /**
     * 魔法の告知文。通常の魔法は「〇〇は△△を唱えた！」、魔法ではない特技（cast_style = skill）は「〇〇の△△！」。
     *
     * @param  array<string, mixed>  $spell
     */
    private function spellAnnounceText(string $actorName, array $spell): string
    {
        if (($spell['cast_style'] ?? '') === 'skill') {
            return "{$actorName}の{$spell['label']}！";
        }

        return "{$actorName}は{$spell['label']}を唱えた！";
    }

    /**
     * この攻撃で回避判定を行うか。
     *
     * - 敵が味方を攻撃するとき … 物理攻撃もダメージ魔法も、常に判定する（味方の素の回避率 5%、または回避バフ）
     * - 味方が敵を攻撃するとき … 素の回避率で判定するのは通常のこぶし（$baseRateApplies）だけ。
     *   回避バフがかかっていれば、どの物理攻撃・ダメージ魔法でも判定する
     *
     * @param  array<string, mixed>  $attacker
     * @param  array<string, mixed>  $target
     */
    private function shouldRollEvasion(array $attacker, array $target, bool $baseRateApplies): bool
    {
        return $baseRateApplies
            || ($attacker['side'] ?? '') === 'enemy'
            || $this->hasEvasionBuff($target);
    }

    /**
     * 回避率を固定する効果（水の幻影など）がかかっているか。
     *
     * @param  array<string, mixed>  $unit
     */
    private function hasEvasionBuff(array $unit): bool
    {
        foreach ($unit['buffs'] ?? [] as $key => $turns) {
            if ($turns > 0 && (config("battle.buffs.{$key}.type") ?? '') === 'evasion_set') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $defender
     */
    private function effectiveEvasionRate(array $defender): int
    {
        $fixed = null;
        foreach ($defender['buffs'] as $key => $turns) {
            if ($turns <= 0) {
                continue;
            }
            $buff = config("battle.buffs.{$key}");
            if (($buff['type'] ?? '') === 'evasion_set') {
                // 回避率固定の効果が複数かかっているときは、いちばん高いものを使う
                $fixed = max($fixed ?? 0, (int) ($buff['value'] ?? 50));
            }
        }

        return $fixed ?? (int) ($defender['evasion_rate'] ?? 0);
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
     * 敵が spell_masters の魔法を唱えるときのターゲット（プレイヤー視点の target_type を敵視点に変換）。
     *
     * @param  array<string, mixed>  $spell
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    private function resolveEnemySpellTargets(array $spell, array $units, string $actorId): array
    {
        $targetType = (string) ($spell['target_type'] ?? 'enemy_single');
        $effect = (string) ($spell['effect'] ?? '');

        if ($targetType === 'enemy_all') {
            return array_keys(array_filter(
                $units,
                fn ($u) => ($u['side'] ?? '') === 'ally' && ($u['alive'] ?? false),
            ));
        }

        if ($targetType === 'enemy_single') {
            $candidates = array_keys(array_filter(
                $units,
                fn ($u) => ($u['side'] ?? '') === 'ally' && ($u['alive'] ?? false),
            ));
            if ($candidates === []) {
                return [];
            }

            return [$candidates[array_rand($candidates)]];
        }

        if ($targetType === 'ally_all') {
            return array_keys(array_filter(
                $units,
                fn ($u) => ($u['side'] ?? '') === 'enemy' && ($u['alive'] ?? false),
            ));
        }

        if ($targetType === 'ally_single') {
            if ($effect === 'heal_mag') {
                // 回復は、いちばん HP の割合が減っている味方に使う
                $wounded = $this->woundedEnemyIds($units);
                usort($wounded, fn ($a, $b) => $units[$a]['hp'] / $units[$a]['max_hp'] <=> $units[$b]['hp'] / $units[$b]['max_hp']);

                return array_slice($wounded, 0, 1);
            }

            if ($effect === 'revive_chance') {
                $candidates = $this->fallenEnemyIds($units);
            } else {
                $candidates = array_keys(array_filter(
                    $units,
                    fn ($u) => ($u['side'] ?? '') === 'enemy' && ($u['alive'] ?? false),
                ));
            }

            if ($candidates === []) {
                return [];
            }

            return [$candidates[array_rand($candidates)]];
        }

        return [];
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
            'fled' => (bool) ($unit['fled'] ?? false),
            'buffs' => $buffs,
            'damage_shield' => (bool) ($unit['damage_shield'] ?? false),
            'life_ward' => (bool) ($unit['life_ward'] ?? false),
            'user_character_id' => $unit['user_character_id'] ?? null,
            'weapon' => $unit['weapon'] ?? null,
            'armor' => $unit['armor'] ?? null,
            'str' => $unit['str'] ?? null,
            'mag' => $unit['mag'] ?? null,
            'def' => $unit['def'] ?? null,
            'pdef' => $unit['pdef'] ?? null,
            'mdef' => $unit['mdef'] ?? null,
            'spd' => $unit['spd'] ?? null,
            'know' => $unit['know'] ?? null,
            'spirit' => $unit['spirit'] ?? null,
            'vit' => $unit['vit'] ?? null,
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

        $punch = config('battle.common_actions.punch');
        $kick = config('battle.common_actions.kick');
        $punch['coefficient'] = $this->physicalCoefficient($unit, 'punch');
        $kick['coefficient'] = $this->physicalCoefficient($unit, 'kick');

        return [
            'punch' => $punch,
            'kick' => $kick,
            'spells' => $spells,
        ];
    }
}
