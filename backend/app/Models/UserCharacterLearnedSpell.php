<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCharacterLearnedSpell extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_character_id',
        'spell_code',
    ];

    /** @return BelongsTo<UserCharacter, $this> */
    public function userCharacter(): BelongsTo
    {
        return $this->belongsTo(UserCharacter::class);
    }
}
