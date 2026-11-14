<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            // 空間の裂け目に入って、ボス戦（4 人の魔法使い）が一度でも始まったか。true になると、層選択画面に「時空の裂け目へ」が出る
            $table->boolean('rift_reached')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn('rift_reached');
        });
    }
};
