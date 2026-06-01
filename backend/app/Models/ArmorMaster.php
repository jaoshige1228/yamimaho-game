<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArmorMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'def_bonus',
        'description',
    ];

    /** @return HasMany<UserCharacter, $this> */
    public function userCharacters(): HasMany
    {
        return $this->hasMany(UserCharacter::class);
    }
}
