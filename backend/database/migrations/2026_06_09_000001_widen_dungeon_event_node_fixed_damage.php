<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_event_nodes', function (Blueprint $table) {
            $table->unsignedInteger('fixed_damage')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_event_nodes', function (Blueprint $table) {
            $table->unsignedSmallInteger('fixed_damage')->nullable()->change();
        });
    }
};
