<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('spell_masters')) {
            Schema::create('spell_masters', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique('spell_masters_code_unique');
                $table->string('label');
                $table->text('description')->nullable();
                $table->unsignedSmallInteger('mp_cost');
                $table->string('target_type', 32);
                $table->string('effect', 32);
                $table->string('element', 16)->nullable();
                $table->decimal('coefficient', 8, 3)->nullable();
                $table->unsignedSmallInteger('power')->nullable();
                $table->string('buff_key', 64)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('character_spell_masters')) {
            Schema::create('character_spell_masters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('character_master_id')
                    ->constrained('character_masters', 'id', 'csm_char_fk')
                    ->cascadeOnDelete();
                $table->foreignId('spell_master_id')
                    ->constrained('spell_masters', 'id', 'csm_spell_fk')
                    ->cascadeOnDelete();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['character_master_id', 'spell_master_id'], 'csm_char_spell_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('character_spell_masters');
        Schema::dropIfExists('spell_masters');
    }
};
