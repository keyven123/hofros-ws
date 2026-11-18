<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'test@hofros.com'],
            [
                'merchant_name' => 'Test Merchant',
                'first_name' => 'Test',
                'middle_name' => 'User',
                'last_name' => 'Test',
                'contact_number' => '1234567890',
                'address' => '1234567890',
                'password' => Hash::make('hofros@2026'),
                'is_platform_admin' => false,
                'is_marketing' => false,
            ],
        );
    }
}
