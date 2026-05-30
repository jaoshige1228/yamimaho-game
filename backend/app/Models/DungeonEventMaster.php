<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DungeonEventMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'weight',
        'event_type',
        'start_node_key',
    ];

    /** @return HasMany<DungeonEventNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(DungeonEventNode::class, 'event_code', 'code');
    }
}
