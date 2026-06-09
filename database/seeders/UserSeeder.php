<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class UserSeeder extends Seeder
{
    public function run(): void
    {
        $alice = User::firstOrCreate(
            ['email' => 'alice@example.com'],
            [
                'name'     => 'Alice (Transactional + Marketing)',
                'password' => Hash::make('password'),

                'can_send_transactional' => true,
            ]
        );
        $alice->tokens()->delete();
        $token = $alice->createToken('alice-token')->plainTextToken;
        $this->command->info("Alice token: {$token}");

        $bob = User::firstOrCreate(
            ['email' => 'bob@example.com'],
            [
                'name'     => 'Bob (Marketing only)',
                'password' => Hash::make('password'),

                'can_send_transactional' => false,
            ]
        );
        $bob->tokens()->delete();
        $token = $bob->createToken('bob-token')->plainTextToken;
        $this->command->info("Bob token: {$token}");
    }
}
