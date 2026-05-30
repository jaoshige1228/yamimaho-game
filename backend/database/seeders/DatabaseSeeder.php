<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedDemoUser();
        $this->seedLocalTestUser();

        $this->call(MasterDataSeeder::class);
        $this->call(CharacterLevelStatPredictionSeeder::class);
    }

    private function seedDemoUser(): void
    {
        User::query()->firstOrCreate(
            ['email' => config('game.demo_user_email')],
            [
                'name' => '勇者',
                'password' => Hash::make('demo-not-for-production'),
            ],
        );
    }

    /** ローカル開発用（Factory / Faker は使わない） */
    private function seedLocalTestUser(): void
    {
        if (app()->environment('production')) {
            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
            ],
        );
    }
}
