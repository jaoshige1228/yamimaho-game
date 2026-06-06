<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CharacterMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'sprite',
        'hp',
        'mp',
        'str',
        'mag',
        'def',
        'spd',
        'know',
        'spirit',
        'vit',
        'default_weapon_code',
        'default_armor_code',
    ];

    /** @return HasMany<UserCharacter, $this> */
    public function userCharacters(): HasMany
    {
        return $this->hasMany(UserCharacter::class);
    }

    /** @return BelongsToMany<SpellMaster, $this> */
    public function spells(): BelongsToMany
    {
        return $this->belongsToMany(SpellMaster::class, 'character_spell_masters')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
