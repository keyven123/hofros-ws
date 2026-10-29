<?php

namespace Tests\Feature\Api;

use App\Models\Property;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class SubscriptionBillingTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testNextInvoiceFollowsActiveRoomsAndFeatureToggles(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $property = Property::factory()->create(['user_uuid' => $merchant->uuid]);
        SubscriptionPlan::query()->update(['is_active' => false]);
        SubscriptionPlan::query()->create([
            'name' => 'Up to 10 rooms',
            'slug' => 'up-to-10',
            'description' => 'Room baseline.',
            'price_per_room' => 100,
            'base_price' => 0,
            'currency' => 'PHP',
            'max_rooms' => 10,
            'modules' => [],
            'sort_order' => 1,
            'is_active' => true,
        ]);
        SubscriptionFeature::query()->update(['monthly_price' => 0]);
        SubscriptionFeature::query()->where('key', 'messages')->update(['monthly_price' => 500]);

        $token = $merchant->createToken('merchant')->plainTextToken;
        $this->withToken($token);

        $unitPayload = [
            'propertyId' => $property->uuid,
            'type' => 'Room',
            'details' => 'A room',
            'maxGuests' => 2,
            'bedrooms' => 1,
            'beds' => 1,
            'pricePerNight' => 1000,
            'status' => 'active',
        ];

        $this->postJson('/api/v1/configuration/units', [...$unitPayload, 'name' => 'Room 1'])->assertCreated();
        $this->postJson('/api/v1/configuration/units', [...$unitPayload, 'name' => 'Room 2'])->assertCreated();

        $this->getJson('/api/v1/subscription/upcoming-invoice')
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.amount', 200)
            ->assertJsonPath('roomsSubtotal', 200)
            ->assertJsonPath('modulesSubtotal', 500)
            ->assertJsonPath('total', 700)
            ->assertJsonPath('prorated', false)
            ->assertJsonFragment(['key' => 'messages', 'amount' => 500]);

        $third = $this->postJson('/api/v1/configuration/units', [...$unitPayload, 'name' => 'Room 3'])->assertCreated();

        $this->getJson('/api/v1/subscription/upcoming-invoice')
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3)
            ->assertJsonPath('total', 800);

        $this->deleteJson('/api/v1/configuration/units/'.$third->json('data.uuid'))
            ->assertOk();

        $this->getJson('/api/v1/subscription/upcoming-invoice')
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('total', 700);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testInactiveRoomsAreNotBilled(): void
    {
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        SubscriptionPlan::query()->update(['is_active' => false]);
        SubscriptionPlan::query()->where('slug', 'regular')->update([
            'is_active' => true,
            'price_per_room' => 149,
            'max_rooms' => 20,
        ]);
        Unit::factory()->create([
            'user_uuid' => $merchant->uuid,
            'status' => Unit::STATUS_ACTIVE,
        ]);
        Unit::factory()->create([
            'user_uuid' => $merchant->uuid,
            'status' => Unit::STATUS_INACTIVE,
        ]);

        $invoice = app(SubscriptionBillingService::class)->calculateNextInvoice($merchant->uuid);

        $this->assertSame(1, $invoice['items'][0]['quantity']);
        $this->assertSame(149.0, $invoice['items'][0]['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMissedDueDateSuspendsTheAccountUntilPayment(): void
    {
        SubscriptionFeature::query()->update(['monthly_price' => 0]);
        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $merchant->forceFill(['created_at' => now()->subMonths(3)])->save();
        $token = $merchant->createToken('merchant')->plainTextToken;
        $this->withToken($token);

        $this->getJson('/api/v1/customers')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');

        $this->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('invoice.overdue', true)
            ->assertJsonPath('invoice.accountStatus', 'suspended');

        $paid = $this->postJson('/api/v1/subscription/pay')
            ->assertOk()
            ->assertJsonPath('overdue', false)
            ->assertJsonPath('accountStatus', 'active');

        $this->assertTrue(now()->startOfDay()->lte(\Illuminate\Support\Carbon::parse($paid->json('dueOn'))->startOfDay()));

        $this->getJson('/api/v1/subscription/payments')
            ->assertOk()
            ->assertJsonCount(1, 'payments')
            ->assertJsonPath('payments.0.status', 'paid');

        $this->getJson('/api/v1/customers')->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testCheckoutStartsPayMongoAndConfirmsThePayment(): void
    {
        SubscriptionFeature::query()->update(['monthly_price' => 0]);
        SubscriptionFeature::query()->where('key', 'messages')->update(['monthly_price' => 100]);
        Config::set('services.paymongo.secret_key', 'sk_test_checkout');
        Config::set('services.paymongo.frontend_url', 'http://localhost:3000');

        $merchant = User::factory()->create(['is_platform_admin' => false]);
        $this->withToken($merchant->createToken('merchant')->plainTextToken);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_1',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_1',
                    ],
                ],
            ]),
            'https://api.paymongo.com/v1/checkout_sessions/cs_test_1' => Http::response([
                'data' => [
                    'id' => 'cs_test_1',
                    'attributes' => [
                        'payments' => [
                            ['attributes' => ['status' => 'paid']],
                        ],
                    ],
                ],
            ]),
        ]);

        $started = $this->postJson('/api/v1/subscription/checkout')
            ->assertOk()
            ->assertJsonPath('checkoutUrl', 'https://checkout.paymongo.com/cs_test_1');

        $this->postJson('/api/v1/subscription/checkout/confirm', [
            'reference' => $started->json('reference'),
        ])
            ->assertOk()
            ->assertJsonPath('paid', true)
            ->assertJsonPath('invoice.accountStatus', 'active');
    }
}
