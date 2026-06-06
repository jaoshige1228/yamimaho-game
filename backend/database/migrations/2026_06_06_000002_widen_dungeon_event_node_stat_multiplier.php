<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_event_nodes', function (Blueprint $table) {
            $table->unsignedSmallInteger('stat_multiplier')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_event_nodes', function (Blueprint $table) {
            $table->unsignedTinyInteger('stat_multiplier')->nullable()->change();
        });
    }
};
