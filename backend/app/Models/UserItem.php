<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'item_master_id',
        'quantity',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ItemMaster, $this> */
    public function itemMaster(): BelongsTo
    {
        return $this->belongsTo(ItemMaster::class);
    }
}
