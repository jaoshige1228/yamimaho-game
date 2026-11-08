<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ユーザーの使い魔。魔石を与えるとレベルが上がり、戦闘中の支援行動が解放される。
 */
class UserFamiliar extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'level',
        'stones_total',
        'stones_progress',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
