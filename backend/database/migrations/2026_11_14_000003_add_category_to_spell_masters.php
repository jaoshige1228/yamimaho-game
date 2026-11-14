<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spell_masters', function (Blueprint $table) {
            // 戦闘のコマンド画面での大分け: attack（攻撃）/ heal（回復）/ support（補助）。空 = 効果の種類から決める
            $table->string('category', 16)->nullable()->after('hp_cost');
        });
    }

    public function down(): void
    {
        Schema::table('spell_masters', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
