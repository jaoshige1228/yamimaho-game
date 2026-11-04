<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_characters', function (Blueprint $table) {
            // レベル成長とは別に、イベント報酬などで加算された最大MP
            $table->unsignedInteger('max_mp_bonus')->default(0)->after('vit');
        });
    }

    public function down(): void
    {
        Schema::table('user_characters', function (Blueprint $table) {
            $table->dropColumn('max_mp_bonus');
        });
    }
};
