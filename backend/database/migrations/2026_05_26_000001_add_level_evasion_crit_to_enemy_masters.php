<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('enemy_masters', 'level')) {
                $table->unsignedInteger('level')->default(1);
            }
            if (! Schema::hasColumn('enemy_masters', 'evasion_rate')) {
                $table->unsignedTinyInteger('evasion_rate')->default(1);
            }
            if (! Schema::hasColumn('enemy_masters', 'crit_rate')) {
                $table->unsignedTinyInteger('crit_rate')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropColumn(['level', 'evasion_rate', 'crit_rate']);
        });
    }
};
