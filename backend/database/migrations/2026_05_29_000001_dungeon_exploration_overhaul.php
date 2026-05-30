<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->boolean('skip_battle_encounters')->default(false)->after('step');
        });

        Schema::create('dungeon_event_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->string('event_type', 64);
            $table->string('start_node_key', 64);
            $table->timestamps();
        });

        Schema::create('dungeon_event_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('event_code', 64)->index();
            $table->string('node_key', 64);
            $table->string('node_type', 32);
            $table->text('text')->nullable();
            $table->string('speaker_role', 64)->nullable();
            $table->text('dialogue_pc1')->nullable();
            $table->text('dialogue_pc2')->nullable();
            $table->text('dialogue_pc3')->nullable();
            $table->text('dialogue_pc4')->nullable();
            $table->string('stat_attr', 16)->nullable();
            $table->unsignedTinyInteger('stat_multiplier')->nullable();
            $table->unsignedSmallInteger('fixed_damage')->nullable();
            $table->string('sfx', 32)->nullable();
            $table->string('next_on_success', 64)->nullable();
            $table->string('next_on_fail', 64)->nullable();
            $table->string('next_default', 64)->nullable();
            $table->timestamps();

            $table->unique(['event_code', 'node_key']);
        });

        Schema::create('dungeon_encounter_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->json('enemies');
            $table->boolean('boss')->default(false);
            $table->timestamps();
        });

        Schema::create('dungeon_exploration_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_code', 64);
            $table->string('current_node_key', 64)->nullable();
            $table->json('context')->nullable();
            $table->unsignedSmallInteger('step_at_start');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dungeon_exploration_sessions');
        Schema::dropIfExists('dungeon_encounter_masters');
        Schema::dropIfExists('dungeon_event_nodes');
        Schema::dropIfExists('dungeon_event_masters');
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn('skip_battle_encounters');
        });
    }
};
