<?php

namespace App\Support;

final class OtaPortalSchemas
{
    public const KEYS = ['booking_com', 'airbnb', 'agoda', 'tripadvisor'];

    /**
     * Merchant credential fields required to integrate each OTA with Hofros.
     *
     * @return array<string, array{
     *   name: string,
     *   description: string,
     *   fields: list<array{key: string, label: string, type: string, required: bool, secret?: bool, placeholder?: string, help?: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            'booking_com' => [
                'name' => 'Booking.com',
                'description' => 'Connect your Booking.com property using Connectivity API credentials so Hofros can sync availability, rates, and reservations.',
                'fields' => [
                    [
                        'key' => 'hotelId',
                        'label' => 'Hotel / Property ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'e.g. 1234567',
                        'help' => 'Your Booking.com hotel ID from the Extranet or Connectivity portal.',
                    ],
                    [
                        'key' => 'clientId',
                        'label' => 'Client ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'Connectivity client ID',
                        'help' => 'OAuth / machine-account client ID issued for your Connectivity contract.',
                    ],
                    [
                        'key' => 'clientSecret',
                        'label' => 'Client secret',
                        'type' => 'password',
                        'required' => true,
                        'secret' => true,
                        'help' => 'Keep this private. Used to authenticate Hofros with Booking.com.',
                    ],
                    [
                        'key' => 'legalEntityId',
                        'label' => 'Legal entity ID (optional)',
                        'type' => 'text',
                        'required' => false,
                        'placeholder' => 'Optional',
                        'help' => 'Required for some multi-property Connectivity setups.',
                    ],
                ],
            ],
            'airbnb' => [
                'name' => 'Airbnb',
                'description' => 'Connect with an Airbnb User ID to capture listings. You can add more User IDs later and set each active or inactive.',
                'fields' => [
                    [
                        'key' => 'userId',
                        'label' => 'User ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'Input User ID',
                        'help' => 'First Airbnb host User ID. After connect you can add more User IDs and toggle them active/inactive.',
                    ],
                ],
            ],
            'agoda' => [
                'name' => 'Agoda',
                'description' => 'Connect Agoda YCS / partner API credentials so Hofros can push inventory and pull bookings.',
                'fields' => [
                    [
                        'key' => 'hotelId',
                        'label' => 'Hotel ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'Agoda hotel ID',
                    ],
                    [
                        'key' => 'siteId',
                        'label' => 'Site / Partner ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'Site ID',
                        'help' => 'Partner or site identifier provided by Agoda for your channel connection.',
                    ],
                    [
                        'key' => 'apiKey',
                        'label' => 'API key',
                        'type' => 'password',
                        'required' => true,
                        'secret' => true,
                    ],
                    [
                        'key' => 'apiSecret',
                        'label' => 'API secret (optional)',
                        'type' => 'password',
                        'required' => false,
                        'secret' => true,
                        'help' => 'Some Agoda partner contracts issue an API secret in addition to the key.',
                    ],
                ],
            ],
            'tripadvisor' => [
                'name' => 'Trip.com',
                'description' => 'Connect Trip.com (Ctrip) partner credentials so Hofros can sync hotel inventory and reservations.',
                'fields' => [
                    [
                        'key' => 'hotelId',
                        'label' => 'Hotel code / Hotel ID',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'Trip.com hotel code',
                    ],
                    [
                        'key' => 'allianceId',
                        'label' => 'Alliance ID (AID)',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'AID',
                    ],
                    [
                        'key' => 'siteId',
                        'label' => 'Site ID (SID)',
                        'type' => 'text',
                        'required' => true,
                        'placeholder' => 'SID',
                    ],
                    [
                        'key' => 'apiKey',
                        'label' => 'API key',
                        'type' => 'password',
                        'required' => true,
                        'secret' => true,
                    ],
                    [
                        'key' => 'apiSecret',
                        'label' => 'API secret (optional)',
                        'type' => 'password',
                        'required' => false,
                        'secret' => true,
                    ],
                ],
            ],
        ];
    }

    public static function isSupported(string $portalKey): bool
    {
        return array_key_exists($portalKey, self::all());
    }

    /**
     * @return array{name: string, description: string, fields: list<array<string, mixed>>}
     */
    public static function for(string $portalKey): array
    {
        $all = self::all();
        if (! isset($all[$portalKey])) {
            throw new \InvalidArgumentException('Unsupported OTA portal: '.$portalKey);
        }

        return $all[$portalKey];
    }

    /**
     * @return list<string>
     */
    public static function secretKeys(string $portalKey): array
    {
        $keys = [];
        foreach (self::for($portalKey)['fields'] as $field) {
            if (! empty($field['secret'])) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(string $portalKey): array
    {
        $keys = [];
        foreach (self::for($portalKey)['fields'] as $field) {
            if (! empty($field['required'])) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }
}
