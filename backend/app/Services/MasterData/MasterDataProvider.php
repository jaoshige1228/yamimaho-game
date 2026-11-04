<?php

namespace App\Services\MasterData;

use App\Models\ArmorMaster;
use App\Models\CharacterMaster;
use App\Models\EnemyMaster;
use App\Models\SpellMaster;
use App\Models\WeaponMaster;
use Illuminate\Support\Facades\Schema;

class MasterDataProvider
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $characterCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $enemyCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $spellCache = null;

    /** @var array<string, list<array{spell_code: string, learn_after_defeat: string|null}>>|null */
    private ?array $characterSpellCache = null;

    /** @var array<string, list<array<string, mixed>>>|null */
    private ?array $enemySkillCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $weaponCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $armorCache = null;

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
     * @return array{code: string, name: string, mag_bonus: int, description: string|null}
     */
    public function findWeapon(string $code): array
    {
        $all = $this->weaponMasters();

        if (! isset($all[$code])) {
            throw new \InvalidArgumentException("Weapon master not found: {$code}");
        }

        return $all[$code];
    }

    /**
     * @return array{code: string, name: string, def_bonus: int, description: string|null}
     */
    public function findArmor(string $code): array
    {
        $all = $this->armorMasters();

        if (! isset($all[$code])) {
            throw new \InvalidArgumentException("Armor master not found: {$code}");
        }

        return $all[$code];
    }

    /**
     * キャラクターが使える魔法（並び順どおり）。最初から使える魔法に加えて、
     * 敵を倒して覚える魔法のうち $learnedSpellCodes に入っているものを含める。
     *
     * @param  list<string>  $learnedSpellCodes  すでに覚えた魔法のコード
     * @return list<string>
     */
    public function spellIdsForCharacter(string $characterCode, array $learnedSpellCodes = []): array
    {
        $ids = [];
        foreach ($this->characterSpellMap()[$characterCode] ?? [] as $entry) {
            if ($entry['learn_after_defeat'] === null || in_array($entry['spell_code'], $learnedSpellCodes, true)) {
                $ids[] = $entry['spell_code'];
            }
        }

        return $ids;
    }

    /**
     * 指定した敵のどれかを倒したときに、キャラクターが覚える魔法。
     *
     * @param  list<string>  $defeatedEnemyCodes
     * @return list<string>
     */
    public function spellIdsLearnedByDefeating(string $characterCode, array $defeatedEnemyCodes): array
    {
        $ids = [];
        foreach ($this->characterSpellMap()[$characterCode] ?? [] as $entry) {
            if ($entry['learn_after_defeat'] !== null && in_array($entry['learn_after_defeat'], $defeatedEnemyCodes, true)) {
                $ids[] = $entry['spell_code'];
            }
        }

        return $ids;
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
     * @return array<string, list<array{spell_code: string, learn_after_defeat: string|null}>>
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
     * @return array<string, list<array{spell_code: string, learn_after_defeat: string|null}>>
     */
    private function loadCharacterSpellsFromDb(): array
    {
        $map = [];

        CharacterMaster::query()
            ->with(['spells' => fn ($q) => $q->orderByPivot('sort_order')])
            ->get()
            ->each(function (CharacterMaster $character) use (&$map): void {
                $map[$character->code] = $character->spells
                    ->map(fn (SpellMaster $spell) => [
                        'spell_code' => $spell->code,
                        'learn_after_defeat' => self::nullIfBlank($spell->pivot->learn_after_defeat ?? null),
                    ])
                    ->values()
                    ->all();
            });

        return $map;
    }

    /**
     * @return array<string, list<array{spell_code: string, learn_after_defeat: string|null}>>
     */
    private function loadCharacterSpellsFromCsv(): array
    {
        $rows = CsvMasterReader::read('character_spells.csv');
        usort($rows, fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $map = [];
        foreach ($rows as $row) {
            $characterCode = $row['character_code'];
            $map[$characterCode] ??= [];
            $map[$characterCode][] = [
                'spell_code' => $row['spell_code'],
                'learn_after_defeat' => self::nullIfBlank($row['learn_after_defeat'] ?? null),
            ];
        }

        return $map;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, array{code: string, name: string, mag_bonus: int, description: string|null}>
     */
    private function weaponMasters(): array
    {
        if ($this->weaponCache === null) {
            if ($this->hasWeaponMastersTable() && WeaponMaster::query()->exists()) {
                $this->weaponCache = $this->loadWeaponsFromDb();
            } else {
                $this->weaponCache = $this->loadWeaponsFromCsv();
            }
        }

        return $this->weaponCache;
    }

    /**
     * @return array<string, array{code: string, name: string, def_bonus: int, description: string|null}>
     */
    private function armorMasters(): array
    {
        if ($this->armorCache === null) {
            if ($this->hasArmorMastersTable() && ArmorMaster::query()->exists()) {
                $this->armorCache = $this->loadArmorsFromDb();
            } else {
                $this->armorCache = $this->loadArmorsFromCsv();
            }
        }

        return $this->armorCache;
    }

    /**
     * @return array<string, array{code: string, name: string, mag_bonus: int, description: string|null}>
     */
    private function loadWeaponsFromDb(): array
    {
        $masters = [];
        WeaponMaster::query()->get()->each(function (WeaponMaster $weapon) use (&$masters): void {
            $masters[$weapon->code] = [
                'code' => $weapon->code,
                'name' => $weapon->name,
                'mag_bonus' => $weapon->mag_bonus,
                'description' => $weapon->description,
            ];
        });

        return $masters;
    }

    /**
     * @return array<string, array{code: string, name: string, def_bonus: int, description: string|null}>
     */
    private function loadArmorsFromDb(): array
    {
        $masters = [];
        ArmorMaster::query()->get()->each(function (ArmorMaster $armor) use (&$masters): void {
            $masters[$armor->code] = [
                'code' => $armor->code,
                'name' => $armor->name,
                'def_bonus' => $armor->def_bonus,
                'description' => $armor->description,
            ];
        });

        return $masters;
    }

    /**
     * @return array<string, array{code: string, name: string, mag_bonus: int, description: string|null}>
     */
    private function loadWeaponsFromCsv(): array
    {
        $masters = [];
        foreach (CsvMasterReader::read('weapon_masters.csv') as $row) {
            $masters[$row['code']] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'mag_bonus' => (int) $row['mag_bonus'],
                'description' => ($row['description'] ?? '') !== '' ? (string) $row['description'] : null,
            ];
        }

        return $masters;
    }

    /**
     * @return array<string, array{code: string, name: string, def_bonus: int, description: string|null}>
     */
    private function loadArmorsFromCsv(): array
    {
        $masters = [];
        foreach (CsvMasterReader::read('armor_masters.csv') as $row) {
            $masters[$row['code']] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'def_bonus' => (int) $row['def_bonus'],
                'description' => ($row['description'] ?? '') !== '' ? (string) $row['description'] : null,
            ];
        }

        return $masters;
    }

    private function hasWeaponMastersTable(): bool
    {
        return Schema::hasTable('weapon_masters');
    }

    private function hasArmorMastersTable(): bool
    {
        return Schema::hasTable('armor_masters');
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
            'second_name' => (string) ($row['second_name'] ?? ''),
            'sprite' => (string) $row['sprite'],
            'hp' => (int) $row['hp'],
            'mp' => (int) ($row['mp'] ?? 0),
            'str' => (int) $row['str'],
            'mag' => (int) $row['mag'],
            'def' => (int) $row['def'],
            'pdef' => (int) ($row['pdef'] ?? $row['def']),
            'mdef' => (int) ($row['mdef'] ?? $row['def']),
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
