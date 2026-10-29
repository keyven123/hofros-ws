<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminAuthTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testPlatformAdminCanLoginViaAdminEndpoint(): void
    {
        User::factory()->create([
            'email' => 'admin@hofros.com',
            'password' => Hash::make('123Hofros$$$'),
            'is_platform_admin' => true,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@hofros.com',
            'password' => '123Hofros$$$',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['uuid', 'email', 'is_platform_admin'],
            ])
            ->assertJsonPath('user.is_platform_admin', true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminLoginFailsWithWrongPassword(): void
    {
        User::factory()->create([
            'email' => 'admin@hofros.com',
            'password' => Hash::make('123Hofros$$$'),
            'is_platform_admin' => true,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@hofros.com',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCannotLoginViaAdminEndpoint(): void
    {
        User::factory()->create([
            'email' => 'merchant@example.com',
            'password' => Hash::make('secret123'),
            'is_platform_admin' => false,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => 'merchant@example.com',
            'password' => 'secret123',
        ])
            ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPlatformAdminCannotUseMerchantLoginEndpoint(): void
    {
        User::factory()->create([
            'email' => 'admin@hofros.com',
            'password' => Hash::make('123Hofros$$$'),
            'is_platform_admin' => true,
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'admin@hofros.com',
            'password' => '123Hofros$$$',
        ])
            ->assertStatus(422);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPlatformAdminTokenRejectedOnMerchantApi(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@hofros.com',
            'password' => Hash::make('123Hofros$$$'),
            'is_platform_admin' => true,
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/dashboard')
            ->assertStatus(403);
    }
}
