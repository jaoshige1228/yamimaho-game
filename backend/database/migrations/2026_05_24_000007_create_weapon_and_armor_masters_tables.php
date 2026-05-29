<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weapon_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('mag_bonus');
            $table->timestamps();
        });

        Schema::create('armor_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('def_bonus');
            $table->timestamps();
        });

        Schema::table('character_masters', function (Blueprint $table) {
            $table->string('default_weapon_code')->nullable()->after('spirit');
            $table->string('default_armor_code')->nullable()->after('default_weapon_code');
        });

        Schema::table('user_characters', function (Blueprint $table) {
            $table->foreignId('weapon_master_id')->nullable()->after('spirit')->constrained('weapon_masters')->nullOnDelete();
            $table->foreignId('armor_master_id')->nullable()->after('weapon_master_id')->constrained('armor_masters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('armor_master_id');
            $table->dropConstrainedForeignId('weapon_master_id');
        });

        Schema::table('character_masters', function (Blueprint $table) {
            $table->dropColumn(['default_weapon_code', 'default_armor_code']);
        });

        Schema::dropIfExists('armor_masters');
        Schema::dropIfExists('weapon_masters');
    }
};
