<?php

namespace App\Services\Battle;

use App\Models\CharacterMaster;
use App\Models\User;
use App\Models\UserCharacter;
use App\Services\Growth\LevelGrowthService;
use App\Services\MasterData\MasterDataProvider;
use App\Services\Player\EquipmentService;
use App\Services\Player\UserCharacterService;
use Illuminate\Support\Str;

class BattleFactory
{
    public function __construct(
        private readonly MasterDataProvider $masters = new MasterDataProvider,
        private readonly LevelGrowthService $growth = new LevelGrowthService,
        private readonly UserCharacterService $userCharacters = new UserCharacterService,
        private readonly EquipmentService $equipment = new EquipmentService,
    ) {}

    public function createDemo(string $enemyConfigKey = 'demo_enemies'): array
    {
        $units = [];

        foreach (config('battle.demo_party') as $slotId => $meta) {
            $masterCode = $meta['master_code'];
            $master = $this->masters->findCharacter($masterCode);
            $spells = $this->masters->spellIdsForCharacter($masterCode);
            $units[$slotId] = $this->makeUnit($slotId, 'ally', $master, $spells);
        }

        $this->appendEnemies($units, $enemyConfigKey);

        return $this->finalizeNewBattle($units);
    }

    /**
     * @param  list<array<string, string>>  $enemyList
     * @param  array<string, mixed>  $meta
     */
    public function createForUserWithEnemies(User $user, array $enemyList, array $meta = []): array
    {
        $party = $this->userCharacters->ensureParty($user);
        $units = [];

        foreach (config('game.party_slots', []) as $slotId => $masterCode) {
            /** @var UserCharacter|null $character */
            $character = $party->get($slotId);
            if ($character === null) {
                continue;
            }

            $master = $character->characterMaster;
            $spells = $this->masters->spellIdsForCharacter($masterCode);
            $units[$slotId] = $this->makeUnitFromUserCharacter($slotId, $character, $master, $spells);
        }

        $this->appendEnemiesFromList($units, $enemyList);

        $state = $this->finalizeNewBattle($units, '戦闘が始まった！');
        $state['user_id'] = $user->id;
        $state['meta'] = $meta;

        return $state;
    }

    public function createForUser(User $user, string $enemyConfigKey = 'demo_enemies'): array
    {
        $party = $this->userCharacters->ensureParty($user);
        $units = [];

        foreach (config('game.party_slots', []) as $slotId => $masterCode) {
            /** @var UserCharacter|null $character */
            $character = $party->get($slotId);
            if ($character === null) {
                continue;
            }

            $master = $character->characterMaster;
            $spells = $this->masters->spellIdsForCharacter($masterCode);
            $units[$slotId] = $this->makeUnitFromUserCharacter($slotId, $character, $master, $spells);
        }

        $this->appendEnemies($units, $enemyConfigKey);

        $state = $this->finalizeNewBattle($units);
        $state['user_id'] = $user->id;
        $state['log'] = ['戦闘が始まった！'];

        return $state;
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     */
    private function appendEnemies(array &$units, string $enemyConfigKey): void
    {
        $enemyList = config("battle.{$enemyConfigKey}");
        if (! is_array($enemyList)) {
            throw new \InvalidArgumentException("Unknown enemy config: {$enemyConfigKey}");
        }

        $this->appendEnemiesFromList($units, $enemyList);
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  list<array<string, string>>  $enemyList
     */
    private function appendEnemiesFromList(array &$units, array $enemyList): void
    {
        foreach ($enemyList as $meta) {
            $master = $this->masters->findEnemy($meta['master_code']);
            $sprite = (string) ($meta['sprite'] ?? $master['sprite'] ?? $master['code']);
            if (! preg_match('/^[a-z][a-z0-9_]*$/i', $sprite)) {
                $sprite = (string) $master['code'];
            }

            $units[$meta['slot']] = $this->makeUnit($meta['slot'], 'enemy', [
                ...$master,
                'name' => $meta['name'],
                'sprite' => $sprite,
            ]);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @return array<string, mixed>
     */
    private function finalizeNewBattle(array $units, string $logMessage = 'デモ戦闘が始まった！'): array
    {
        $turnOrder = $this->buildTurnOrder($units);
        $currentActor = $this->findNextActor($units, $turnOrder, 0);

        return [
            'status' => 'active',
            'turn_order' => $turnOrder,
            'turn_index' => 0,
            'current_actor' => $currentActor,
            'awaiting_input' => $currentActor !== null && str_starts_with($currentActor, 'pc'),
            'units' => $units,
            'log' => [$logMessage],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @return list<string>
     */
    public function buildTurnOrder(array $units): array
    {
        $ids = array_keys($units);

        usort($ids, function (string $a, string $b) use ($units): int {
            $spdA = (int) ($units[$a]['spd'] ?? 0);
            $spdB = (int) ($units[$b]['spd'] ?? 0);

            if ($spdA !== $spdB) {
                return $spdB <=> $spdA;
            }

            return strcmp($a, $b);
        });

        return $ids;
    }

    /**
     * @param  list<string>  $spells
     * @return array<string, mixed>
     */
    private function makeUnitFromUserCharacter(
        string $slotId,
        UserCharacter $character,
        CharacterMaster $master,
        array $spells = [],
    ): array {
        $maxStats = $this->growth->statsForLevel($master, $character->level);
        $combat = $this->equipment->effectiveCombatStats($character);

        return [
            'id' => $slotId,
            'side' => 'ally',
            'name' => $master->name,
            'sprite' => $master->sprite,
            'master_code' => $master->code,
            'user_character_id' => $character->id,
            'level' => $character->level,
            'exp' => $character->exp,
            'hp' => min($character->hp, $maxStats['hp']),
            'max_hp' => $maxStats['hp'],
            'mp' => min($character->mp, $maxStats['mp']),
            'max_mp' => $maxStats['mp'],
            'str' => $character->str,
            'mag' => $combat['mag'],
            'def' => $combat['def'],
            'spd' => $character->spd,
            'know' => $character->know,
            'spirit' => $character->spirit,
            'vit' => $character->vit,
            'weapon' => $combat['weapon'],
            'armor' => $combat['armor'],
            'alive' => $character->hp > 0,
            'evasion_rate' => (int) config('battle.ally_defaults.evasion_rate', 5),
            'crit_rate' => (int) config('battle.ally_defaults.crit_rate', 5),
            'damage_shield' => false,
            'buffs' => [],
            'statuses' => [],
            'spells' => $spells,
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  list<string>  $spells
     * @return array<string, mixed>
     */
    private function makeUnit(string $id, string $side, array $master, array $spells = []): array
    {
        $isEnemy = $side === 'enemy';
        $allyDefaults = config('battle.ally_defaults', []);

        return [
            'id' => $id,
            'side' => $side,
            'name' => $master['name'],
            'sprite' => $master['sprite'],
            'master_code' => $master['code'],
            'user_character_id' => null,
            'level' => $isEnemy ? (int) ($master['level'] ?? 1) : 1,
            'exp' => 0,
            'hp' => $master['hp'],
            'max_hp' => $master['hp'],
            'mp' => $master['mp'],
            'max_mp' => $master['mp'],
            'str' => $master['str'],
            'mag' => $master['mag'],
            'def' => $master['def'],
            'spd' => $master['spd'],
            'know' => $master['know'],
            'spirit' => $master['spirit'],
            'vit' => $master['vit'] ?? 10,
            'gold_reward' => $isEnemy ? (int) ($master['gold_reward'] ?? 0) : 0,
            'alive' => true,
            'evasion_rate' => $isEnemy
                ? (int) ($master['evasion_rate'] ?? 1)
                : (int) ($allyDefaults['evasion_rate'] ?? 5),
            'crit_rate' => $isEnemy
                ? (int) ($master['crit_rate'] ?? 1)
                : (int) ($allyDefaults['crit_rate'] ?? 5),
            'damage_shield' => false,
            'buffs' => [],
            'statuses' => [],
            'spells' => $spells,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $units
     * @param  list<string>  $turnOrder
     */
    public function findNextActor(array $units, array $turnOrder, int $startIndex): ?string
    {
        $count = count($turnOrder);
        for ($i = 0; $i < $count; $i++) {
            $idx = ($startIndex + $i) % $count;
            $id = $turnOrder[$idx];
            if (($units[$id]['alive'] ?? false) === true) {
                return $id;
            }
        }

        return null;
    }

    public function findSpell(string $spellId): array
    {
        return $this->masters->findSpell($spellId);
    }

    public function newBattleId(): string
    {
        return (string) Str::uuid();
    }
}
