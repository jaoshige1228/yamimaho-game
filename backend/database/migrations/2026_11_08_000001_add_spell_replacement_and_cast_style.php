<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_spell_masters', function (Blueprint $table) {
            // 入っている魔法コードを覚えると、この魔法は使えなくなる（強化版への置き換え）。空 = 置き換えなし
            $table->string('replaced_by', 64)->nullable()->after('learn_after_defeat');
        });

        Schema::table('spell_masters', function (Blueprint $table) {
            // 空 = 通常の魔法（「〇〇は△△を唱えた！」）。skill = 魔法ではない特技（「〇〇の△△！」）
            $table->string('cast_style', 16)->nullable()->after('buff_key');
        });
    }

    public function down(): void
    {
        Schema::table('spell_masters', function (Blueprint $table) {
            $table->dropColumn('cast_style');
        });

        Schema::table('character_spell_masters', function (Blueprint $table) {
            $table->dropColumn('replaced_by');
        });
    }
};
