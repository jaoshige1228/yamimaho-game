<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 武具屋に並ぶために必要な「突破した層」（0 = 最初から並ぶ）。CSV の unlock_floor 列
        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('unlock_floor')->default(0)->after('price');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('unlock_floor')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->dropColumn('unlock_floor');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->dropColumn('unlock_floor');
        });
    }
};
