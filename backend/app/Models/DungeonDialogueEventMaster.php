<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DungeonDialogueEventMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'floor',
        'code',
        'name',
        'weight',
        'start_node_key',
    ];

    /** @return HasMany<DungeonEventNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(DungeonEventNode::class, 'event_code', 'code');
    }
}
