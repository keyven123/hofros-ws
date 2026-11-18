<?php

namespace Tests\Feature\Api;

use App\Models\Booking;
use App\Models\BookingCheckout;
use App\Models\BookingPayment;
use App\Models\BookingPortalConnection;
use App\Models\MerchantPaymentGateway;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class MerchantPaymentGatewayTest extends ApiTestCase
{
    private function publishedMerchant(string $merchantName = 'Acme Stays'): User
    {
        $user = User::factory()->create(['merchant_name' => $merchantName]);
        BookingPortalConnection::create([
            'user_uuid' => $user->uuid,
            'portal_key' => 'direct_website',
            'is_connected' => true,
            'is_active' => true,
            'guest_portal_live' => true,
            'guest_portal_design_completed' => true,
            'guest_portal_headline' => 'Welcome',
            'guest_portal_message' => 'Hi there',
            'listing_count' => 1,
        ]);

        return $user;
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCanCreateAndActivateOnlyOneGateway(): void
    {
        $merchant = $this->authenticate();

        $first = $this->postJson('/api/v1/payment-gateways', [
            'provider' => 'paymongo',
            'name' => 'PayMongo Test',
            'publicKey' => 'pk_test_abcdefghijklmnop',
            'secretKey' => 'sk_test_abcdefghijklmnop',
            'activate' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('gateway.status', 'active')
            ->json('gateway.id');

        $second = $this->postJson('/api/v1/payment-gateways', [
            'provider' => 'paypal',
            'name' => 'PayPal Sandbox',
            'environment' => 'testing',
            'publicKey' => 'paypal-client-id-12345678',
            'secretKey' => 'paypal-client-secret-12345678',
            'activate' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('gateway.status', 'active')
            ->json('gateway.id');

        $this->assertDatabaseHas('merchant_payment_gateways', [
            'uuid' => $first,
            'status' => MerchantPaymentGateway::STATUS_INACTIVE,
        ]);
        $this->assertDatabaseHas('merchant_payment_gateways', [
            'uuid' => $second,
            'status' => MerchantPaymentGateway::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/payment-gateways/'.$first.'/activate')
            ->assertOk()
            ->assertJsonPath('gateway.status', 'active');

        $this->assertDatabaseHas('merchant_payment_gateways', [
            'uuid' => $second,
            'status' => MerchantPaymentGateway::STATUS_INACTIVE,
        ]);

        $this->getJson('/api/v1/payment-gateways')
            ->assertOk()
            ->assertJsonCount(2, 'gateways')
            ->assertJsonPath('activeGatewayId', $first);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPublicPortalExposesActiveGatewayAndStartsCheckoutOnBook(): void
    {
        Config::set('services.paymongo.frontend_url', 'http://localhost:3000');

        $user = $this->publishedMerchant('Acme Stays');
        MerchantPaymentGateway::query()->create([
            'user_uuid' => $user->uuid,
            'provider' => MerchantPaymentGateway::PROVIDER_PAYMONGO,
            'name' => 'PayMongo Live',
            'environment' => MerchantPaymentGateway::ENV_TESTING,
            'public_key' => 'pk_test_abcdefghijklmnop',
            'secret_key' => 'sk_test_abcdefghijklmnop',
            'status' => MerchantPaymentGateway::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/public/direct-portals/acme-stays')
            ->assertOk()
            ->assertJsonPath('data.paymentGateway.provider', 'paymongo')
            ->assertJsonPath('data.paymentGateway.onlineCheckout', true);

        $property = Property::factory()->create(['user_uuid' => $user->uuid]);
        $unit = Unit::factory()->create([
            'user_uuid' => $user->uuid,
            'property_uuid' => $property->uuid,
            'max_guests' => 4,
            'price_per_night' => 1500,
            'currency' => 'PHP',
            'status' => Unit::STATUS_ACTIVE,
        ]);

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_portal_1',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_portal_1',
                    ],
                ],
            ]),
            'https://api.paymongo.com/v1/checkout_sessions/cs_portal_1' => Http::response([
                'data' => [
                    'id' => 'cs_portal_1',
                    'attributes' => [
                        'payments' => [
                            ['attributes' => ['status' => 'paid']],
                        ],
                    ],
                ],
            ]),
        ]);

        $booked = $this->postJson('/api/v1/public/direct-portals/acme-stays/bookings', [
            'unitId' => $unit->uuid,
            'guestName' => 'Jane Doe',
            'guestEmail' => 'jane@example.com',
            'guestPhone' => '+639170001111',
            'checkIn' => Carbon::today()->addDays(30)->toDateString(),
            'checkOut' => Carbon::today()->addDays(33)->toDateString(),
            'adults' => 2,
            'children' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('paymentRequired', true)
            ->assertJsonPath('paymentProvider', 'paymongo')
            ->assertJsonPath('checkoutUrl', null);

        $batchId = $booked->json('portalBatchId');
        $totalAmount = (float) $booked->json('totalAmount');
        $this->assertNotEmpty($batchId);
        $this->assertSame(round($totalAmount / 2, 2), (float) $booked->json('reservationAmount'));

        $this->getJson('/api/v1/public/direct-portals/acme-stays/checkout/'.$batchId)
            ->assertOk()
            ->assertJsonPath('portalBatchId', $batchId)
            ->assertJsonPath('paymentGateway.provider', 'paymongo');

        $started = $this->postJson('/api/v1/public/direct-portals/acme-stays/checkout/'.$batchId.'/pay', [
            'mode' => 'reservation',
        ])
            ->assertOk()
            ->assertJsonPath('checkoutUrl', 'https://checkout.paymongo.com/cs_portal_1')
            ->assertJsonPath('paymentMode', 'reservation');

        $this->assertEqualsWithDelta(round($totalAmount / 2, 2), (float) $started->json('amount'), 0.001);

        $checkoutId = $started->json('checkoutId');
        $this->assertNotEmpty($checkoutId);
        $this->assertDatabaseHas('booking_checkouts', [
            'uuid' => $checkoutId,
            'status' => BookingCheckout::STATUS_PENDING,
            'provider' => 'paymongo',
        ]);
        $this->assertEqualsWithDelta(
            round($totalAmount / 2, 2),
            (float) BookingCheckout::query()->whereKey($checkoutId)->value('amount'),
            0.001,
        );

        $this->postJson('/api/v1/public/direct-portals/acme-stays/payments/confirm', [
            'ref' => $checkoutId,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paymentMode', 'reservation');

        $this->assertDatabaseHas('booking_checkouts', [
            'uuid' => $checkoutId,
            'status' => BookingCheckout::STATUS_PAID,
        ]);
        $this->assertDatabaseHas('booking_payments', [
            'user_uuid' => $user->uuid,
            'payment_type' => BookingPayment::TYPE_CARD,
            'transaction_kind' => BookingPayment::KIND_PAYMENT,
        ]);
        $this->assertDatabaseHas('bookings', [
            'unit_uuid' => $unit->uuid,
            'status' => Booking::STATUS_ACCEPTED,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testPaynamicsRequiresApiCredentials(): void
    {
        $this->authenticate();

        $this->postJson('/api/v1/payment-gateways', [
            'provider' => 'paynamics',
            'name' => 'Paynamics',
            'environment' => 'testing',
            'publicKey' => 'merchant-id-12345678',
            'secretKey' => 'merchant-key-12345678',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['apiUsername', 'apiPassword']);
    }
}
