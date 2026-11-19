<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Unit;
use App\Services\Ticketoc\HofrosRoomCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory and reservations for Ticketoc Stay, which sells Hofros rooms as an OTA.
 */
class PartnerTicketocController extends Controller
{
    public function __construct(private HofrosRoomCatalog $rooms) {}

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
        return response()->json(['properties' => $this->rooms->all()]);
    }

    public function unit(string $unitId): JsonResponse
    {
        $unit = Unit::query()->with(['dateBlocks', 'bookings', 'property'])->find($unitId);
        if ($unit === null) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        return response()->json(['unit' => $this->rooms->unitPayload($unit)]);
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

}
