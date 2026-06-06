<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->unsignedInteger('gold_reward')->default(0)->after('exp_reward');
            $table->unsignedInteger('vit')->default(5)->after('spirit');
        });

        Schema::table('character_masters', function (Blueprint $table) {
            $table->unsignedInteger('vit')->default(10)->after('spirit');
        });

        Schema::table('user_characters', function (Blueprint $table) {
            $table->unsignedInteger('vit')->default(10)->after('spirit');
        });

        Schema::table('character_level_stat_predictions', function (Blueprint $table) {
            $table->unsignedInteger('vit')->default(10)->after('spirit');
        });

        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable()->after('description');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable()->after('description');
        });

        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->boolean('in_dungeon')->default(false)->after('skip_battle_encounters');
        });

        Schema::table('battle_states', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'status']);
        });

        Schema::create('item_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('effect', 32);
            $table->unsignedInteger('power')->default(0);
            $table->timestamps();
        });

        Schema::create('user_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_master_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'item_master_id']);
        });

        Schema::create('user_character_owned_weapons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weapon_master_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_character_id', 'weapon_master_id'], 'uc_owned_weapon_unique');
        });

        Schema::create('user_character_owned_armors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('armor_master_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_character_id', 'armor_master_id'], 'uc_owned_armor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_character_owned_armors');
        Schema::dropIfExists('user_character_owned_weapons');
        Schema::dropIfExists('user_items');
        Schema::dropIfExists('item_masters');

        Schema::table('battle_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn('in_dungeon');
        });

        Schema::table('armor_masters', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('weapon_masters', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('character_level_stat_predictions', function (Blueprint $table) {
            $table->dropColumn('vit');
        });

        Schema::table('user_characters', function (Blueprint $table) {
            $table->dropColumn('vit');
        });

        Schema::table('character_masters', function (Blueprint $table) {
            $table->dropColumn('vit');
        });

        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropColumn(['gold_reward', 'vit']);
        });
    }
};
