<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DungeonEventNode extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_code',
        'node_key',
        'node_type',
        'text',
        'speaker_role',
        'dialogue_pc1',
        'dialogue_pc2',
        'dialogue_pc3',
        'dialogue_pc4',
        'stat_attr',
        'stat_multiplier',
        'fixed_damage',
        'sfx',
        'next_on_success',
        'next_on_fail',
        'next_default',
    ];

    /**
     * 層ごとの上書き（dungeon_event_floor_overrides.csv）で stat_multiplier が差し替えられたか。
     * true のときは、その値がその層で使う倍率そのものなので、層補正（stat_multiplier_scale）をかけない。
     * DB の列ではなく、読み込んだ時にメモリ上で付けるフラグ。
     */
    public bool $statMultiplierOverridden = false;

    /** @return BelongsTo<DungeonEventMaster, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(DungeonEventMaster::class, 'event_code', 'code');
    }
}
