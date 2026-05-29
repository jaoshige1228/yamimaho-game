<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SpellMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'label',
        'description',
        'mp_cost',
        'target_type',
        'effect',
        'element',
        'coefficient',
        'power',
        'buff_key',
    ];

    /** @return BelongsToMany<CharacterMaster, $this> */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(CharacterMaster::class, 'character_spell_masters')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
