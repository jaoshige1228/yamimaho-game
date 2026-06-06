<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCharacterOwnedArmor extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_character_id',
        'armor_master_id',
    ];

    /** @return BelongsTo<UserCharacter, $this> */
    public function userCharacter(): BelongsTo
    {
        return $this->belongsTo(UserCharacter::class);
    }

    /** @return BelongsTo<ArmorMaster, $this> */
    public function armorMaster(): BelongsTo
    {
        return $this->belongsTo(ArmorMaster::class);
    }
}
