<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory and reservations for Ticketoc Stay, which sells Hofros rooms as an OTA.
 */
class PartnerTicketocController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'platform' => 'hofros',
            'channel' => 'ticketoc',
        ]);
    }

    public function inventory(): JsonResponse
    {
        $properties = Property::query()
            ->with(['units.dateBlocks', 'units.bookings'])
            ->orderBy('property_name')
            ->get()
            ->map(fn (Property $property) => $this->propertyPayload($property))
            ->values();

        return response()->json(['properties' => $properties]);
    }

    public function unit(string $unitId): JsonResponse
    {
        $unit = Unit::query()->with(['dateBlocks', 'bookings', 'property'])->find($unitId);
        if ($unit === null) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        return response()->json(['unit' => $this->unitPayload($unit)]);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ota_booking_id' => ['required', 'uuid'],
            'order_number' => ['nullable', 'string', 'max:64'],
            'unit_id' => ['required', 'uuid'],
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_email' => ['nullable', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:64'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:16'],
            'specs' => ['nullable', 'array'],
            'rooms' => ['nullable', 'array'],
        ]);

        $unit = Unit::query()->find($data['unit_id']);
        if ($unit === null) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        $reference = 'TKT'.substr(str_replace('-', '', $data['ota_booking_id']), 0, 20);
        $existing = Booking::withTrashed()->where('reference', $reference)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return response()->json([
                'booking_id' => $existing->uuid,
                'reference' => $existing->reference,
                'created' => false,
            ]);
        }

        $specNote = '';
        if (is_array($data['specs'] ?? null) && $data['specs'] !== []) {
            $details = trim((string) ($data['specs']['details'] ?? ''));
            $type = trim((string) ($data['specs']['type'] ?? ''));
            $specNote = trim($type.' '.$details);
        }

        $booking = Booking::query()->create([
            'user_uuid' => $unit->user_uuid,
            'unit_uuid' => $unit->uuid,
            'reference' => $reference,
            'guest_name' => $data['guest_name'],
            'guest_email' => $data['guest_email'] ?? null,
            'guest_phone' => $data['guest_phone'] ?? null,
            'check_in' => $data['check_in_date'],
            'check_out' => $data['check_out_date'],
            'adults' => max(1, (int) ($data['guest_count'] ?? 1)),
            'children' => 0,
            'total_price' => $data['amount'],
            'currency' => substr((string) ($data['currency'] ?: $unit->currency ?: 'PHP'), 0, 16),
            'source' => Booking::SOURCE_TICKETOC,
            'status' => Booking::STATUS_ACCEPTED,
            'notes' => trim('Ticketoc OTA '.($data['order_number'] ?? $data['ota_booking_id']).($specNote !== '' ? ' · '.$specNote : '')),
        ]);

        return response()->json([
            'booking_id' => $booking->uuid,
            'reference' => $booking->reference,
            'created' => true,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyPayload(Property $property): array
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
            'units' => $property->units->map(fn (Unit $unit) => $this->unitPayload($unit))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unitPayload(Unit $unit): array
    {
        $blocks = [];
        foreach ($unit->dateBlocks as $block) {
            $blocks[] = [
                'id' => $block->uuid,
                'start_date' => $block->start_date?->toDateString(),
                'end_date' => $block->end_date?->toDateString(),
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
