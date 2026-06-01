<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->default(1)->after('user_id');
            $table->unsignedTinyInteger('unlocked_floor')->default(1)->after('floor');
        });

        Schema::table('dungeon_encounter_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->default(1)->after('id');
        });

        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->default(1)->after('id');
        });

        Schema::table('dungeon_exploration_sessions', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor_at_start')->default(1)->after('step_at_start');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_exploration_sessions', function (Blueprint $table) {
            $table->dropColumn('floor_at_start');
        });

        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->dropColumn('floor');
        });

        Schema::table('dungeon_encounter_masters', function (Blueprint $table) {
            $table->dropColumn('floor');
        });

        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn(['floor', 'unlocked_floor']);
        });
    }
};
