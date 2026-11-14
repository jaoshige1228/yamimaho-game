<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DungeonEventMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'floor',
        'code',
        'name',
        'weight',
        'event_type',
        'start_node_key',
        'skip_epilogue',
        'once_per_run',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'skip_epilogue' => 'boolean',
            'once_per_run' => 'boolean',
        ];
    }

    /** @return HasMany<DungeonEventNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(DungeonEventNode::class, 'event_code', 'code');
    }
}
