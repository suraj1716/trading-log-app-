<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'h@gmail.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('qwerty123'),
                'email_verified_at' => now(),
            ]
        );
    }
}
