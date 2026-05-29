<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LevelMaster extends Model
{
    protected $primaryKey = 'level';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $fillable = [
        'level',
        'exp_to_next',
        'hp_growth',
        'mp_growth',
        'stat_growth',
    ];
}
