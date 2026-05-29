<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnemyMaster extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
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
        'exp_reward',
    ];
}
