<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'leopardinvestor@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Naeem@0404'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );
    }
}
