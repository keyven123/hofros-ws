<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Hash;

class AdvancedSettingsTest extends ApiTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function testMerchantCanUpdateProfilePasswordAndPaymentSettings(): void
    {
        $user = $this->authenticate();

        $this->getJson('/api/v1/account')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->putJson('/api/v1/account', [
            'merchant_name' => 'Vinz Hotels',
            'first_name' => 'Vinz',
            'middle_name' => null,
            'last_name' => 'Owner',
            'contact_number' => '09170000000',
            'address' => 'Tagaytay',
            'email' => 'vinz@hofros.test',
        ])
            ->assertOk()
            ->assertJsonPath('user.merchant_name', 'Vinz Hotels')
            ->assertJsonPath('user.email', 'vinz@hofros.test');

        $this->putJson('/api/v1/account/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));

        $this->putJson('/api/v1/account/payment', [
            'acceptCash' => true,
            'acceptGcash' => true,
            'acceptBankTransfer' => false,
            'acceptCard' => false,
            'gcashName' => 'Vinz Hotels',
            'gcashNumber' => '09170000000',
            'bankName' => null,
            'bankAccountName' => null,
            'bankAccountNumber' => null,
            'currency' => 'PHP',
            'depositRequired' => true,
            'depositPercent' => 30,
            'instructions' => 'Send the receipt after transfer.',
        ])
            ->assertOk()
            ->assertJsonPath('payment.acceptGcash', true)
            ->assertJsonPath('payment.depositPercent', 30);

        $this->getJson('/api/v1/account/payment')
            ->assertOk()
            ->assertJsonPath('payment.gcashNumber', '09170000000');
    }
}
