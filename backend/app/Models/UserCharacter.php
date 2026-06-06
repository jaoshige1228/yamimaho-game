<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCharacter extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'character_master_id',
        'level',
        'exp',
        'hp',
        'mp',
        'str',
        'mag',
        'def',
        'spd',
        'know',
        'spirit',
        'vit',
        'weapon_master_id',
        'armor_master_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CharacterMaster, $this> */
    public function characterMaster(): BelongsTo
    {
        return $this->belongsTo(CharacterMaster::class);
    }

    /** @return BelongsTo<WeaponMaster, $this> */
    public function weapon(): BelongsTo
    {
        return $this->belongsTo(WeaponMaster::class, 'weapon_master_id');
    }

    /** @return BelongsTo<ArmorMaster, $this> */
    public function armor(): BelongsTo
    {
        return $this->belongsTo(ArmorMaster::class, 'armor_master_id');
    }
}
