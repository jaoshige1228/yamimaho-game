<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('character_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('sprite');
            $table->unsignedInteger('hp');
            $table->unsignedInteger('mp')->default(0);
            $table->unsignedInteger('str');
            $table->unsignedInteger('mag');
            $table->unsignedInteger('def');
            $table->unsignedInteger('spd');
            $table->unsignedInteger('know');
            $table->unsignedInteger('spirit');
            $table->timestamps();
        });

        Schema::create('enemy_masters', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('sprite');
            $table->unsignedInteger('hp');
            $table->unsignedInteger('mp')->default(0);
            $table->unsignedInteger('str');
            $table->unsignedInteger('mag');
            $table->unsignedInteger('def');
            $table->unsignedInteger('spd');
            $table->unsignedInteger('know');
            $table->unsignedInteger('spirit');
            $table->timestamps();
        });

        Schema::create('user_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_master_id')->constrained('character_masters')->cascadeOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedInteger('hp');
            $table->unsignedInteger('mp')->default(0);
            $table->unsignedInteger('str');
            $table->unsignedInteger('mag');
            $table->unsignedInteger('def');
            $table->unsignedInteger('spd');
            $table->unsignedInteger('know');
            $table->unsignedInteger('spirit');
            $table->timestamps();

            $table->unique(['user_id', 'character_master_id'], 'user_char_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_characters');
        Schema::dropIfExists('enemy_masters');
        Schema::dropIfExists('character_masters');
    }
};
