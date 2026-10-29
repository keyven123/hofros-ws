<?php

namespace Tests\Feature\Api;

use App\Models\BookingPortalConnection;
use App\Models\Customer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class GuestPortalMessageTest extends ApiTestCase
{
    private function publishedMerchant(bool $allowMessages = true): User
    {
        $user = User::factory()->create(['merchant_name' => 'Vinz Hotels']);
        BookingPortalConnection::create([
            'user_uuid' => $user->uuid,
            'portal_key' => 'direct_website',
            'is_connected' => true,
            'is_active' => true,
            'guest_portal_live' => true,
            'guest_portal_layout' => ['allowGuestMessages' => $allowMessages],
        ]);

        return $user;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCustomerMustHaveAnAccountToMessage(): void
    {
        $this->publishedMerchant();

        $this->postJson('/api/v1/public/direct-portals/vinz-hotels/messages', [
            'body' => 'Hello',
        ])->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantTokenCannotSendAGuestMessage(): void
    {
        $merchant = $this->publishedMerchant();
        Sanctum::actingAs($merchant);

        $this->postJson('/api/v1/public/direct-portals/vinz-hotels/messages', [
            'body' => 'Hello',
        ])->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCustomerCanMessageWhenTheDesignToggleIsOn(): void
    {
        $merchant = $this->publishedMerchant(true);
        $customer = Customer::query()->create([
            'full_name' => 'Ana Cruz',
            'address' => '1 Palm Street',
            'contact_number' => '+639171234567',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/public/direct-portals/vinz-hotels/messages', [
            'body' => 'Is the pool open?',
        ])->assertCreated()
            ->assertJsonPath('messages.0.text', 'Is the pool open?')
            ->assertJsonPath('messages.0.from', 'customer');

        Sanctum::actingAs($merchant);
        $this->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('threads.0.name', 'Ana Cruz')
            ->assertJsonPath('threads.0.unread', true);

        $this->postJson('/api/v1/messages/'.$customer->uuid, [
            'body' => 'Yes, from 8am.',
        ])->assertCreated()
            ->assertJsonPath('messages.1.from', 'merchant');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCustomerCanMessageFromTheDesignBeforeTheSiteIsPublished(): void
    {
        $merchant = User::factory()->create(['merchant_name' => 'Test Merchant']);
        BookingPortalConnection::create([
            'user_uuid' => $merchant->uuid,
            'portal_key' => 'direct_website',
            'is_connected' => true,
            'is_active' => true,
            'guest_portal_live' => false,
            'guest_portal_layout' => ['allowGuestMessages' => true],
        ]);
        $customer = Customer::query()->create([
            'full_name' => 'Ana Cruz',
            'address' => '1 Palm Street',
            'contact_number' => '09171234567',
            'email' => 'ana.design@example.com',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/public/direct-portals/test-merchant/messages', [
            'body' => 'Hello from the design.',
        ])->assertCreated()
            ->assertJsonPath('messages.0.text', 'Hello from the design.');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCustomerCannotMessageWhenTheDesignToggleIsOff(): void
    {
        $this->publishedMerchant(false);
        $customer = Customer::query()->create([
            'full_name' => 'Ana Cruz',
            'address' => '1 Palm Street',
            'contact_number' => '+639171234567',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/public/direct-portals/vinz-hotels/messages', [
            'body' => 'Hello',
        ])->assertForbidden();
    }
}
