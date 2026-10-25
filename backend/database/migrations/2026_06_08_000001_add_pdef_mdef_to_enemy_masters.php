<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            if (! Schema::hasColumn('enemy_masters', 'pdef')) {
                $table->unsignedInteger('pdef')->default(0)->after('def');
            }
            if (! Schema::hasColumn('enemy_masters', 'mdef')) {
                $table->unsignedInteger('mdef')->default(0)->after('pdef');
            }
        });

        if (Schema::hasColumn('enemy_masters', 'def')) {
            DB::table('enemy_masters')->update([
                'pdef' => DB::raw('def'),
                'mdef' => DB::raw('def'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('enemy_masters', function (Blueprint $table) {
            $table->dropColumn(['pdef', 'mdef']);
        });
    }
};
