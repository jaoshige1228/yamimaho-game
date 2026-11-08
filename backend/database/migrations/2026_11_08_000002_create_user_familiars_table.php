<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_familiars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // 0 = 未解放（支援なし）。魔石を与えて 1〜9 に上がる
            $table->unsignedTinyInteger('level')->default(0);
            // 与えた魔石の総数
            $table->unsignedInteger('stones_total')->default(0);
            // 前回のレベルアップから与えた魔石の数（レベルアップで 0 に戻る）
            $table->unsignedInteger('stones_progress')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_familiars');
    }
};
