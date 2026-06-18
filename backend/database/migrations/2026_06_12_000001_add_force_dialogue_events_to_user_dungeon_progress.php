<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table): void {
            $table->boolean('force_dialogue_events')
                ->default(false)
                ->after('skip_battle_encounters');
        });
    }

    public function down(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table): void {
            $table->dropColumn('force_dialogue_events');
        });
    }
};
