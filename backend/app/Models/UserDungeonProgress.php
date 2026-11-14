<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDungeonProgress extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'floor',
        'unlocked_floor',
        'cleared_floor',
        'step',
        'skip_battle_encounters',
        'force_dialogue_events',
        'in_dungeon',
        'once_event_codes',
        'rift_reached',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skip_battle_encounters' => 'boolean',
            'force_dialogue_events' => 'boolean',
            'in_dungeon' => 'boolean',
            'once_event_codes' => 'array',
            'rift_reached' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
