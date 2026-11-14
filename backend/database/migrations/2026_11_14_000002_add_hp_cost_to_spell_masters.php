<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_masters', function (Blueprint $table) {
            // 唱えると減る HP（固定の値）。魔力集中（effect = mp_heal_hp_cost）で使う。空 = HP は減らない
            $table->unsignedSmallInteger('hp_cost')->nullable()->after('cast_style');
        });
    }

    public function down(): void
    {
        Schema::table('spell_masters', function (Blueprint $table) {
            $table->dropColumn('hp_cost');
        });
    }
};
