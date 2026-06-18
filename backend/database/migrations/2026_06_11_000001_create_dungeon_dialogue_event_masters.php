<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeon_dialogue_event_masters', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('floor');
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedSmallInteger('weight')->default(1);
            $table->string('start_node_key', 64);
            $table->timestamps();

            $table->unique(['floor', 'code']);
        });

        if (! Schema::hasTable('dungeon_event_masters')) {
            return;
        }

        $dialogueCodes = ['stumble_near_fall', 'maj_umai_leaf'];
        $rows = DB::table('dungeon_event_masters')
            ->whereIn('code', $dialogueCodes)
            ->get(['floor', 'code', 'name', 'weight', 'start_node_key']);

        foreach ($rows as $row) {
            DB::table('dungeon_dialogue_event_masters')->updateOrInsert(
                ['floor' => $row->floor, 'code' => $row->code],
                [
                    'name' => $row->name,
                    'weight' => $row->weight,
                    'start_node_key' => $row->start_node_key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        DB::table('dungeon_event_masters')->whereIn('code', $dialogueCodes)->delete();
    }

    public function down(): void
    {
        if (Schema::hasTable('dungeon_dialogue_event_masters') && Schema::hasTable('dungeon_event_masters')) {
            $rows = DB::table('dungeon_dialogue_event_masters')->get();

            foreach ($rows as $row) {
                DB::table('dungeon_event_masters')->updateOrInsert(
                    ['floor' => $row->floor, 'code' => $row->code],
                    [
                        'name' => $row->name,
                        'weight' => $row->weight,
                        'event_type' => 'dialogue_only',
                        'start_node_key' => $row->start_node_key,
                        'skip_epilogue' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        Schema::dropIfExists('dungeon_dialogue_event_masters');
    }
};
