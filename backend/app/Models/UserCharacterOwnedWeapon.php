<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCharacterOwnedWeapon extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_character_id',
        'weapon_master_id',
    ];

    /** @return BelongsTo<UserCharacter, $this> */
    public function userCharacter(): BelongsTo
    {
        return $this->belongsTo(UserCharacter::class);
    }

    /** @return BelongsTo<WeaponMaster, $this> */
    public function weaponMaster(): BelongsTo
    {
        return $this->belongsTo(WeaponMaster::class);
    }
}
