<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->boolean('skip_epilogue')->default(false)->after('start_node_key');
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->dropColumn('skip_epilogue');
        });
    }
};
