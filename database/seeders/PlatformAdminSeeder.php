<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@hofros.com'],
            [
                'merchant_name' => 'Platform Admin',
                'first_name' => 'Platform',
                'middle_name' => null,
                'last_name' => 'Administrator',
                'contact_number' => '0',
                'address' => null,
                'password' => Hash::make('123Hofros$$$'),
                'is_platform_admin' => true,
            ],
        );
    }
}
