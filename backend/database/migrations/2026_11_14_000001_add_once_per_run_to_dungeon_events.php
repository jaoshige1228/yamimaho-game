<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 回のダンジョン挑戦（層に入ってから出るまで）で、最大 1 回しか出ないイベント（dungeon_events.csv の once_per_run 列）
        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->boolean('once_per_run')->default(false)->after('skip_epilogue');
        });

        // 今の挑戦で、すでに出た「1 回だけ」のイベントのコード（層に入るたびに空に戻る）
        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->json('once_event_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dungeon_event_masters', function (Blueprint $table) {
            $table->dropColumn('once_per_run');
        });

        Schema::table('user_dungeon_progress', function (Blueprint $table) {
            $table->dropColumn('once_event_codes');
        });
    }
};
