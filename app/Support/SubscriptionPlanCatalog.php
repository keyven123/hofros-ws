<?php

namespace App\Support;

/**
 * Canonical room-band subscription rates (PHP per room / month).
 * Used by migrations and seeders so every environment shares the same fixed prices.
 */
final class SubscriptionPlanCatalog
{
    /**
     * @return list<array{max_rooms: int|null, price_per_room: float, name: string, slug: string, description: string}>
     */
    public static function baselines(): array
    {
        $bands = [
            1 => 999.00,
            2 => 950.00,
            3 => 900.00,
            4 => 850.00,
            5 => 800.00,
            6 => 775.00,
            7 => 750.00,
            8 => 725.00,
            9 => 700.00,
            10 => 675.00,
            11 => 650.00,
            12 => 625.00,
            13 => 600.00,
            14 => 575.00,
            15 => 550.00,
            16 => 540.00,
            17 => 530.00,
            18 => 520.00,
            19 => 510.00,
        ];

        $plans = [];
        foreach ($bands as $maxRooms => $price) {
            $plans[] = [
                'max_rooms' => $maxRooms,
                'price_per_room' => $price,
                'name' => $maxRooms === 1 ? 'Up to 1 room' : 'Up to '.$maxRooms.' rooms',
                'slug' => 'up-to-'.$maxRooms.'-rooms',
                'description' => 'Fixed monthly rate for up to '.$maxRooms.' active '.($maxRooms === 1 ? 'room' : 'rooms').'.',
            ];
        }

        $plans[] = [
            'max_rooms' => null,
            'price_per_room' => 499.00,
            'name' => 'No room cap',
            'slug' => 'no-room-cap',
            'description' => 'Fixed monthly rate with no room maximum.',
        ];

        return $plans;
    }
}
