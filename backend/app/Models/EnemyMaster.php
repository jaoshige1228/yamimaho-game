<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnemyMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'floor',
        'level',
        'name',
        'sprite',
        'hp',
        'mp',
        'str',
        'mag',
        'def',
        'spd',
        'know',
        'spirit',
        'vit',
        'exp_reward',
        'gold_reward',
        'evasion_rate',
        'crit_rate',
    ];
}
