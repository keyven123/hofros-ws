<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

class AdminMarketingTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testAdminCanRecordAndUpdateATarget(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'merchant_name' => 'Platform Admin',
        ]);
        $this->authenticate($admin);

        $created = $this->postJson('/api/v1/admin/marketing', [
            'kind' => 'market',
            'name' => 'Harbor Hotel',
            'contactName' => 'Ana Reyes',
            'email' => 'ana@harbor.test',
            'phone' => '09170000000',
            'location' => 'Cebu',
            'rooms' => 40,
            'status' => 'target',
            'notes' => 'Beachfront property to onboard.',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Harbor Hotel')
            ->assertJsonPath('kind', 'market');

        $this->putJson('/api/v1/admin/marketing/'.$created->json('id'), [
            'kind' => 'market',
            'name' => 'Harbor Hotel',
            'status' => 'contacted',
            'rooms' => 40,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'contacted');

        $this->getJson('/api/v1/admin/marketing')
            ->assertOk()
            ->assertJsonPath('prospects.0.name', 'Harbor Hotel')
            ->assertJsonPath('prospects.0.status', 'contacted');

        $this->putJson('/api/v1/admin/marketing/'.$created->json('id').'/status', [
            'status' => 'interested',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'interested');

        $this->postJson('/api/v1/admin/marketing/'.$created->json('id').'/notes', [
            'body' => 'Called the front desk. They want a demo.',
        ])
            ->assertCreated()
            ->assertJsonPath('notes', 'Called the front desk. They want a demo.')
            ->assertJsonPath('noteLog.0.body', 'Called the front desk. They want a demo.');

        $this->deleteJson('/api/v1/admin/marketing/'.$created->json('id'))
            ->assertOk();

        $this->getJson('/api/v1/admin/marketing')
            ->assertOk()
            ->assertJsonCount(0, 'prospects');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMarketingAccountSeesOnlyAssignedTargets(): void
    {
        $admin = User::factory()->create([
            'is_platform_admin' => true,
            'merchant_name' => 'Platform Admin',
        ]);
        $this->authenticate($admin);

        $account = $this->postJson('/api/v1/admin/marketing/accounts', [
            'name' => 'Dana Cruz',
            'email' => 'dana@hofros.test',
            'phone' => '09180000000',
            'password' => 'hofros@2026',
        ])
            ->assertCreated()
            ->assertJsonPath('email', 'dana@hofros.test');

        $this->postJson('/api/v1/admin/marketing', [
            'kind' => 'market',
            'name' => 'Harbor Hotel',
            'status' => 'target',
            'ownerUserId' => $account->json('id'),
        ])->assertCreated()->assertJsonPath('ownerName', 'Dana Cruz');

        $this->postJson('/api/v1/admin/marketing', [
            'kind' => 'client',
            'name' => 'Unassigned Client',
            'status' => 'target',
        ])->assertCreated();

        $this->getJson('/api/v1/admin/merchants')
            ->assertOk()
            ->assertJsonMissing(['email' => 'dana@hofros.test']);

        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'dana@hofros.test',
                'role' => 'Marketing',
            ]);

        $this->postJson('/api/v1/login', [
            'email' => 'dana@hofros.test',
            'password' => 'hofros@2026',
        ])->assertStatus(422);

        $this->postJson('/api/v1/marketing/login', [
            'email' => 'dana@hofros.test',
            'password' => 'hofros@2026',
        ])->assertOk()->assertJsonPath('user.name', 'Dana Cruz');

        $marketer = User::query()->where('email', 'dana@hofros.test')->firstOrFail();
        Sanctum::actingAs($marketer);

        $this->getJson('/api/v1/marketing/home')
            ->assertOk()
            ->assertJsonPath('account.name', 'Dana Cruz')
            ->assertJsonPath('counts.total', 1)
            ->assertJsonPath('prospects.0.name', 'Harbor Hotel');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCannotOpenMarketing(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $this->withToken($merchant->createToken('merchant')->plainTextToken)
            ->getJson('/api/v1/admin/marketing')
            ->assertForbidden();
    }
}
