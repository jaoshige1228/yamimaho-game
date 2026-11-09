<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ブラウザごとのセーブを見分けるトークン（Cookie の値）の sha256。生のトークンは保存しない。
            // NULL = ブラウザに紐づかないユーザー（共有のデモユーザーなど）
            $table->string('player_token_hash', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['player_token_hash']);
            $table->dropColumn('player_token_hash');
        });
    }
};
