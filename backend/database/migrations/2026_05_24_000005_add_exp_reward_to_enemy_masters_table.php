<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->unsignedInteger('exp_reward')->default(0)->after('spirit');
        });
    }

    public function down(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropColumn('exp_reward');
        });
    }
};
