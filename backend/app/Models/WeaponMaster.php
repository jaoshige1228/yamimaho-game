<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeaponMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'mag_bonus',
        'description',
        'price',
    ];

    /** @return HasMany<UserCharacter, $this> */
    public function userCharacters(): HasMany
    {
        return $this->hasMany(UserCharacter::class);
    }
}
