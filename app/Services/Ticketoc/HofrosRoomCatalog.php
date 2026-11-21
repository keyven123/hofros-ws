<?php

namespace App\Services\Ticketoc;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Unit;

/**
 * Hofros rooms and accommodations in the shape Ticketoc Stay imports.
 */
final class HofrosRoomCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return Property::query()
            ->with(['units.dateBlocks', 'units.bookings'])
            ->orderBy('property_name')
            ->get()
            ->map(fn (Property $property) => $this->propertyPayload($property))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(string $userUuid): array
    {
        return Property::query()
            ->where('user_uuid', $userUuid)
            ->with(['units.dateBlocks', 'units.bookings'])
            ->orderBy('property_name')
            ->get()
            ->map(fn (Property $property) => $this->propertyPayload($property))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function propertyPayload(Property $property): array
    {
        return [
            'id' => $property->uuid,
            'name' => $property->property_name,
            'address' => $property->address,
            'currency' => $property->currency,
            'check_in_time' => $property->check_in_time,
            'check_out_time' => $property->check_out_time,
            'contact_email' => $property->contact_email,
            'phone' => $property->phone,
            'units' => $property->units->map(fn (Unit $unit) => $this->unitPayload($unit))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function unitPayload(Unit $unit): array
    {
        $blocks = [];
        foreach ($unit->dateBlocks as $block) {
            $blocks[] = [
                'id' => $block->uuid,
                'start_date' => $block->start_date?->toDateString(),
                'end_date' => $block->end_date?->toDateString(),
                'end_exclusive' => true,
                'label' => $block->label,
            ];
        }

        foreach ($unit->bookings as $booking) {
            if ($booking->status === Booking::STATUS_CANCELLED) {
                continue;
            }
            $blocks[] = [
                'id' => 'booking:'.$booking->uuid,
                'start_date' => $booking->check_in?->toDateString(),
                'end_date' => $booking->check_out?->toDateString(),
                'end_exclusive' => true,
                'label' => 'Booked',
            ];
        }

        return [
            'id' => $unit->uuid,
            'name' => $unit->name,
            'description' => $unit->description,
            'details' => $unit->details,
            'type' => $unit->type,
            'max_guests' => $unit->max_guests,
            'bedrooms' => $unit->bedrooms,
            'beds' => $unit->beds,
            'price_per_night' => (string) $unit->price_per_night,
            'currency' => $unit->currency,
            'status' => $unit->status,
            'images' => $unit->images ?? [],
            'week_schedule' => $unit->week_schedule,
            'specs' => [
                'type' => $unit->type,
                'details' => $unit->details,
                'max_guests' => $unit->max_guests,
                'bedrooms' => $unit->bedrooms,
                'beds' => $unit->beds,
            ],
            'blocks' => $blocks,
        ];
    }
}
