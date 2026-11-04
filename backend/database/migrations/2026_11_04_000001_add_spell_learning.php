<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_spell_masters', function (Blueprint $table) {
            // 空 = 最初から使える。敵のコードが入っている魔法は、その敵を倒したときに覚える
            $table->string('learn_after_defeat', 64)->nullable()->after('sort_order');
        });

        Schema::create('user_character_learned_spells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_character_id')->constrained()->cascadeOnDelete();
            $table->string('spell_code', 64);
            $table->timestamps();

            $table->unique(['user_character_id', 'spell_code'], 'uc_learned_spell_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_character_learned_spells');

        Schema::table('character_spell_masters', function (Blueprint $table) {
            $table->dropColumn('learn_after_defeat');
        });
    }
};
