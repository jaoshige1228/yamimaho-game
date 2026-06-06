<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'effect',
        'power',
    ];

    /** @return HasMany<UserItem, $this> */
    public function userItems(): HasMany
    {
        return $this->hasMany(UserItem::class);
    }
}
