<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_level_stat_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_master_id')
                ->constrained('character_masters')
                ->cascadeOnDelete()
                ->name('clsp_char_fk');
            $table->unsignedSmallInteger('level');
            $table->unsignedInteger('hp');
            $table->unsignedInteger('mp');
            $table->unsignedInteger('str');
            $table->unsignedInteger('mag');
            $table->unsignedInteger('def');
            $table->unsignedInteger('spd');
            $table->unsignedInteger('know');
            $table->unsignedInteger('spirit');
            $table->timestamps();

            $table->unique(['character_master_id', 'level'], 'clsp_char_level_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_level_stat_predictions');
    }
};
