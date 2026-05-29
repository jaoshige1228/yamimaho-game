<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('level_masters')) {
            Schema::create('level_masters', function (Blueprint $table) {
                $table->unsignedSmallInteger('level')->primary();
                $table->unsignedInteger('exp_to_next');
                $table->decimal('hp_growth', 5, 3);
                $table->decimal('mp_growth', 5, 3);
                $table->decimal('stat_growth', 5, 3);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('level_masters');
    }
};
