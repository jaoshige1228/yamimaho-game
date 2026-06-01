<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->unsignedTinyInteger('floor')->default(1)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropColumn('floor');
        });
    }
};
