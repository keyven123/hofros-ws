<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\GuestMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class CustomerListingTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantSeesCustomersWhoMessagedTheProperty(): void
    {
        $merchant = User::factory()->create(['merchant_name' => 'Vinz Hotels']);
        $other = User::factory()->create(['merchant_name' => 'Other Hotel']);
        $customer = Customer::query()->create([
            'full_name' => 'Ana Cruz',
            'address' => '1 Palm Street',
            'contact_number' => '09171234567',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ]);
        $stranger = Customer::query()->create([
            'full_name' => 'Ben Santos',
            'address' => '9 Bay Road',
            'contact_number' => '09170000000',
            'email' => 'ben@example.com',
            'password' => 'password123',
        ]);
        GuestMessage::query()->create([
            'merchant_user_uuid' => $merchant->uuid,
            'customer_uuid' => $customer->uuid,
            'sender' => 'customer',
            'body' => 'Hello',
        ]);
        GuestMessage::query()->create([
            'merchant_user_uuid' => $other->uuid,
            'customer_uuid' => $stranger->uuid,
            'sender' => 'customer',
            'body' => 'Hi',
        ]);

        Sanctum::actingAs($merchant);

        $this->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Ana Cruz')
            ->assertJsonPath('customers.0.address', '1 Palm Street')
            ->assertJsonPath('customers.0.contactNumber', '09171234567')
            ->assertJsonPath('customers.0.email', 'ana@example.com')
            ->assertJsonMissing(['email' => 'ben@example.com']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCustomerAccountCannotListCustomers(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'Ana Cruz',
            'address' => '1 Palm Street',
            'contact_number' => '09171234567',
            'email' => 'ana@example.com',
            'password' => 'password123',
        ]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/customers')->assertForbidden();
    }
}
