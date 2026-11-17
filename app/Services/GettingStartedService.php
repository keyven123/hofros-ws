<?php

namespace App\Services;

use App\Http\Repositories\BookingPortalRepository;
use App\Models\BookingPortalConnection;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UnitRateInterval;
use App\Models\User;

class GettingStartedService
{
    /**
     * @return list<array{id: string, title: string, description: string, to: string, done: bool}>
     */
    public function stepsFor(User $merchant): array
    {
        $hasProperty = Property::query()->where('user_uuid', $merchant->uuid)->exists();
        $hasRoom = Unit::query()->where('user_uuid', $merchant->uuid)->exists();
        $hasRates = UnitRateInterval::query()->where('user_uuid', $merchant->uuid)->exists()
            || Unit::query()
                ->where('user_uuid', $merchant->uuid)
                ->whereNotNull('price_per_night')
                ->where('price_per_night', '>', 0)
                ->exists();

        $direct = BookingPortalConnection::query()
            ->where('user_uuid', $merchant->uuid)
            ->where('portal_key', BookingPortalRepository::DIRECT_WEBSITE_KEY)
            ->first();

        $hasDirectWebsite = $direct
            && (
                (bool) $direct->is_connected
                || (bool) $direct->guest_portal_live
                || (bool) $direct->guest_portal_design_completed
            );

        return [
            [
                'id' => 'accommodation',
                'title' => 'Create an accommodation',
                'description' => 'Add your property details in Configuration so rooms and rates have a place to live.',
                'to' => '/configuration?tab=property',
                'done' => $hasProperty,
            ],
            [
                'id' => 'rooms',
                'title' => 'Create rooms',
                'description' => 'Add at least one active room or unit that guests can book.',
                'to' => '/configuration?tab=units',
                'done' => $hasRoom,
            ],
            [
                'id' => 'rates',
                'title' => 'Set availability & base rates',
                'description' => 'Save nightly prices so the calendar and booking site know what to charge.',
                'to' => '/configuration?tab=rates',
                'done' => $hasRates,
            ],
            [
                'id' => 'direct_website',
                'title' => 'Create your direct website',
                'description' => 'Open Booking Portals, build your direct website, and publish a guest booking link.',
                'to' => '/bookingportals',
                'done' => $hasDirectWebsite,
            ],
        ];
    }

    /**
     * @return array{dismissed: bool, completed: bool, steps: list<array<string, mixed>>, remaining: int}
     */
    public function payloadFor(User $merchant): array
    {
        $steps = $this->stepsFor($merchant);
        $remaining = count(array_filter($steps, static fn (array $step): bool => ! $step['done']));

        return [
            'dismissed' => $merchant->getting_started_dismissed_at !== null,
            'completed' => $remaining === 0,
            'remaining' => $remaining,
            'steps' => $steps,
        ];
    }
}
