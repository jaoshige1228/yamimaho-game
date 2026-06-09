<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DungeonFloorMaster extends Model
{
    protected $primaryKey = 'floor';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'floor',
        'stat_multiplier_scale',
    ];

    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'stat_multiplier_scale' => 'integer',
        ];
    }
}
