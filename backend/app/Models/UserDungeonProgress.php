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
        'step',
        'skip_battle_encounters',
        'in_dungeon',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skip_battle_encounters' => 'boolean',
            'in_dungeon' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
