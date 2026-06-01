<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->text('description')->nullable()->after('mag_bonus');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->text('description')->nullable()->after('def_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
