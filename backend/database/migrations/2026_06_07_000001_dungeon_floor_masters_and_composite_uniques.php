<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_floor_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->primary();
            $table->unsignedTinyInteger('stat_multiplier_scale')->default(100);
            $table->timestamps();
        });

        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['floor', 'code']);
        });

        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['floor', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropUnique(['floor', 'code']);
            $table->unique(['code']);
        });

        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->dropUnique(['floor', 'code']);
            $table->unique(['code']);
        });

        Schema::dropIfExists('dungeon_floor_masters');
    }
};
