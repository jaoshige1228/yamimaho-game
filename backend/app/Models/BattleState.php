<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BattleState extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'state',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
        ];
    }
}
