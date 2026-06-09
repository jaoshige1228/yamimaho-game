<?php

namespace App\Services\MasterData;

use App\Models\CharacterMaster;
use App\Models\EnemyMaster;
use App\Models\SpellMaster;
use Illuminate\Support\Facades\Schema;

class MasterDataProvider
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $characterCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $enemyCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $spellCache = null;

    /** @var array<string, list<string>>|null */
    private ?array $characterSpellCache = null;

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $enemySkillCache = null;

    /**
     * @return array<string, mixed>
     */
    public function findCharacter(string $code): array
    {
        $all = $this->characterMasters();

        if (! isset($all[$code])) {
            throw new \InvalidArgumentException("Character master not found: {$code}");
        }

        return $all[$code];
    }

    public static function enemyKey(int $floor, string $code): string
    {
        return "{$floor}.{$code}";
    }

    /**
     * @return array<string, mixed>
     */
    public function findEnemy(string $code, ?int $floor = null): array
    {
        $floor = $floor ?? 1;
        $all = $this->enemyMasters();
        $key = self::enemyKey($floor, $code);

        if (! isset($all[$key])) {
            throw new \InvalidArgumentException("Enemy master not found: {$code} (floor {$floor})");
        }

        return [...$all[$key]];
    }

    /**
     * @return array<string, mixed>
     */
    public function findSpell(string $code): array
    {
        $all = $this->spellMasters();

        if (! isset($all[$code])) {
            throw new \InvalidArgumentException("Spell master not found: {$code}");
        }

        return $all[$code];
    }

    /**
     * @return list<string>
     */
    public function spellIdsForCharacter(string $characterCode): array
    {
        $map = $this->characterSpellMap();

        return $map[$characterCode] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enemySkillsFor(string $enemyCode): array
    {
        $all = $this->enemySkills();

        return $all[$enemyCode] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function enemySkills(): array
    {
        if ($this->enemySkillCache !== null) {
            return $this->enemySkillCache;
        }

        $grouped = [];
        foreach (CsvMasterReader::read('enemy_skills.csv') as $row) {
            $enemyCode = (string) $row['enemy_code'];
            $grouped[$enemyCode] ??= [];
            $grouped[$enemyCode][] = [
                'skill_code' => (string) $row['skill_code'],
                'label' => (string) $row['label'],
                'weight' => (int) $row['weight'],
                'action_type' => (string) $row['action_type'],
                'target_type' => (string) $row['target_type'],
                'coefficient' => (float) $row['coefficient'],
            ];
        }

        $this->enemySkillCache = $grouped;

        return $this->enemySkillCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function characterMasters(): array
    {
        if ($this->characterCache !== null) {
            return $this->characterCache;
        }

        if ($this->hasCharacterMastersTable() && CharacterMaster::query()->exists()) {
            $this->characterCache = CharacterMaster::query()
                ->get()
                ->keyBy('code')
                ->map(fn (CharacterMaster $m) => $this->normalizeMaster($m->toArray()))
                ->all();
        } else {
            $this->characterCache = $this->loadCharactersFromCsv();
        }

        return $this->characterCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enemyMasters(): array
    {
        if ($this->enemyCache !== null) {
            return $this->enemyCache;
        }

        if ($this->hasEnemyMastersTable() && EnemyMaster::query()->exists()) {
            $this->enemyCache = EnemyMaster::query()
                ->get()
                ->keyBy(fn (EnemyMaster $m) => self::enemyKey((int) $m->floor, (string) $m->code))
                ->map(fn (EnemyMaster $m) => $this->normalizeEnemy($m->toArray()))
                ->all();
        } else {
            $this->enemyCache = $this->loadEnemiesFromCsv();
        }

        return $this->enemyCache;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function spellMasters(): array
    {
        if ($this->spellCache !== null) {
            return $this->spellCache;
        }

        if ($this->hasSpellMastersTable() && SpellMaster::query()->exists()) {
            $this->spellCache = SpellMaster::query()
                ->get()
                ->keyBy('code')
                ->map(fn (SpellMaster $m) => $this->normalizeSpell($m->toArray()))
                ->all();
        } else {
            $this->spellCache = $this->loadSpellsFromCsv();
        }

        return $this->spellCache;
    }

    /**
     * @return array<string, list<string>>
     */
    private function characterSpellMap(): array
    {
        if ($this->characterSpellCache !== null) {
            return $this->characterSpellCache;
        }

        if ($this->hasCharacterSpellMastersTable() && Schema::hasTable('character_spell_masters')) {
            $this->characterSpellCache = $this->loadCharacterSpellsFromDb();
        } else {
            $this->characterSpellCache = $this->loadCharacterSpellsFromCsv();
        }

        return $this->characterSpellCache;
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadCharacterSpellsFromDb(): array
    {
        $map = [];

        CharacterMaster::query()
            ->with(['spells' => fn ($q) => $q->orderByPivot('sort_order')])
            ->get()
            ->each(function (CharacterMaster $character) use (&$map): void {
                $map[$character->code] = $character->spells
                    ->pluck('code')
                    ->values()
                    ->all();
            });

        return $map;
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadCharacterSpellsFromCsv(): array
    {
        $rows = CsvMasterReader::read('character_spells.csv');
        usort($rows, fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $map = [];
        foreach ($rows as $row) {
            $characterCode = $row['character_code'];
            $map[$characterCode] ??= [];
            $map[$characterCode][] = $row['spell_code'];
        }

        return $map;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadCharactersFromCsv(): array
    {
        $masters = [];
        foreach (CsvMasterReader::read('character_masters.csv') as $row) {
            $masters[$row['code']] = $this->normalizeMaster($row);
        }

        return $masters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadEnemiesFromCsv(): array
    {
        $masters = [];
        foreach (CsvMasterReader::read('enemy_masters.csv') as $row) {
            $floor = (int) ($row['floor'] ?? 1);
            $code = (string) $row['code'];
            $masters[self::enemyKey($floor, $code)] = $this->normalizeEnemy($row);
        }

        return $masters;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadSpellsFromCsv(): array
    {
        $masters = [];
        foreach (CsvMasterReader::read('spell_masters.csv') as $row) {
            $masters[$row['code']] = $this->normalizeSpell($row);
        }

        return $masters;
    }

    private function hasCharacterMastersTable(): bool
    {
        return Schema::hasTable('character_masters');
    }

    private function hasEnemyMastersTable(): bool
    {
        return Schema::hasTable('enemy_masters');
    }

    private function hasSpellMastersTable(): bool
    {
        return Schema::hasTable('spell_masters');
    }

    private function hasCharacterSpellMastersTable(): bool
    {
        return Schema::hasTable('character_spell_masters')
            && $this->hasCharacterMastersTable()
            && $this->hasSpellMastersTable()
            && CharacterMaster::query()->exists()
            && SpellMaster::query()->exists();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeEnemy(array $row): array
    {
        return [
            ...$this->normalizeMaster($row),
            'floor' => (int) ($row['floor'] ?? 1),
            'level' => (int) ($row['level'] ?? 1),
            'exp_reward' => (int) ($row['exp_reward'] ?? 0),
            'gold_reward' => (int) ($row['gold_reward'] ?? 0),
            'evasion_rate' => (int) ($row['evasion_rate'] ?? 1),
            'crit_rate' => (int) ($row['crit_rate'] ?? 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeMaster(array $row): array
    {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'sprite' => (string) $row['sprite'],
            'hp' => (int) $row['hp'],
            'mp' => (int) ($row['mp'] ?? 0),
            'str' => (int) $row['str'],
            'mag' => (int) $row['mag'],
            'def' => (int) $row['def'],
            'spd' => (int) $row['spd'],
            'know' => (int) $row['know'],
            'spirit' => (int) $row['spirit'],
            'vit' => (int) ($row['vit'] ?? 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeSpell(array $row): array
    {
        $spell = [
            'label' => (string) $row['label'],
            'description' => (string) ($row['description'] ?? ''),
            'mp_cost' => (int) $row['mp_cost'],
            'target_type' => (string) $row['target_type'],
            'effect' => (string) $row['effect'],
        ];

        if (($row['element'] ?? '') !== '') {
            $spell['element'] = (string) $row['element'];
        }

        if (($row['coefficient'] ?? '') !== '') {
            $spell['coefficient'] = (float) $row['coefficient'];
        }

        if (($row['power'] ?? '') !== '') {
            $spell['power'] = (int) $row['power'];
        }

        $buffKey = (string) ($row['buff_key'] ?? '');
        if ($buffKey !== '') {
            $spell['buff'] = $buffKey;
        }

        return $spell;
    }
}
