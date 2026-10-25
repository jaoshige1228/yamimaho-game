<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_masters', function (Blueprint $table): void {
            $table->string('second_name')->default('')->after('name');
        });

        $defaults = [
            'pc1' => 'シナノガワ',
            'pc2' => 'カレイド',
            'pc3' => 'ウメキダ',
            'pc4' => 'アカリヤ',
        ];

        foreach ($defaults as $code => $secondName) {
            DB::table('character_masters')->where('code', $code)->update(['second_name' => $secondName]);
        }
    }

    public function down(): void
    {
        Schema::table('character_masters', function (Blueprint $table): void {
            $table->dropColumn('second_name');
        });
    }
};
