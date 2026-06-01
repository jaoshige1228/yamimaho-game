<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DungeonEncounterMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'floor',
        'code',
        'weight',
        'enemies',
        'boss',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enemies' => 'array',
            'boss' => 'boolean',
        ];
    }
}
