<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            // クリア済みの最深層（0 = どの層もクリアしていない）。最終層（3層）は解放層だけでは表せないため別に持つ
            $table->unsignedTinyInteger('cleared_floor')->default(0)->after('unlocked_floor');
        });

        // 既存のセーブ: 次の層が解放されていれば、その手前の層はクリア済み
        DB::table('user_dungeon_progress')->update(['cleared_floor' => DB::raw('unlocked_floor - 1')]);
    }

    public function down(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn('cleared_floor');
        });
    }
};
