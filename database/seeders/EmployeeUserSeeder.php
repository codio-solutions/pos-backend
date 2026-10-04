<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'rep1@khanenterprises.test'],
            [
                'name' => 'Test Rep',
                'password' => Hash::make('change-this-password'),
                'role' => 'employee',
                'email_verified_at' => now(),
            ]
        );
    }
}
