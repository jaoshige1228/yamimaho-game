<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterLevelStatPrediction extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'character_master_id',
        'level',
        'hp',
        'mp',
        'str',
        'mag',
        'def',
        'spd',
        'know',
        'spirit',
        'vit',
    ];

    /** @return BelongsTo<CharacterMaster, $this> */
    public function characterMaster(): BelongsTo
    {
        return $this->belongsTo(CharacterMaster::class);
    }
}
