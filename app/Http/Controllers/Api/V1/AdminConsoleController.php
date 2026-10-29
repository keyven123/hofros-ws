<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPortalConnection;
use App\Models\Customer;
use App\Models\Property;
use App\Models\SubscriptionPayment;
use App\Models\TeamMember;
use App\Models\Unit;
use App\Models\UnitDateBlock;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AdminConsoleController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function dashboard(): JsonResponse
    {
        $today = now()->startOfDay();
        $start = $today->copy()->startOfMonth()->subMonths(3);

        $recent = Booking::query()
            ->with(['user:uuid,merchant_name', 'unit:uuid,name'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Booking $booking) => $this->bookingPayload($booking));

        $window = Booking::query()
            ->whereDate('check_in', '>=', $start->toDateString())
            ->get(['check_in', 'total_price', 'status']);

        $byMonth = [];
        for ($index = 0; $index < 6; $index++) {
            $cursor = $start->copy()->addMonths($index);
            $byMonth[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M'),
                'bookings' => 0,
                'revenue' => 0,
            ];
        }

        foreach ($window as $booking) {
            $key = substr((string) $this->dateString($booking->check_in), 0, 7);
            if (! isset($byMonth[$key]) || $booking->status === Booking::STATUS_CANCELLED) {
                continue;
            }
            $byMonth[$key]['bookings']++;
            $byMonth[$key]['revenue'] = round($byMonth[$key]['revenue'] + (float) $booking->total_price, 2);
        }

        $statusCounts = Booking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $upcoming = Booking::query()
            ->with(['user:uuid,merchant_name', 'unit:uuid,name'])
            ->whereDate('check_in', '>=', $today->toDateString())
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->orderBy('check_in')
            ->limit(6)
            ->get()
            ->map(fn (Booking $booking) => $this->bookingPayload($booking));

        $inHouse = Booking::query()
            ->whereDate('check_in', '<=', $today->toDateString())
            ->whereDate('check_out', '>', $today->toDateString())
            ->whereNotIn('status', [Booking::STATUS_CANCELLED, Booking::STATUS_CHECKED_OUT])
            ->count();

        $merchants = User::merchants()
            ->withCount(['units', 'bookings'])
            ->orderByDesc('bookings_count')
            ->limit(6)
            ->get()
            ->map(fn (User $user) => [
                'name' => $user->merchant_name,
                'rooms' => (int) $user->units_count,
                'bookings' => (int) $user->bookings_count,
            ]);

        return response()->json([
            'counts' => [
                'merchants' => User::merchants()->count(),
                'rooms' => Unit::query()->count(),
                'activeRooms' => Unit::query()->where('status', Unit::STATUS_ACTIVE)->count(),
                'bookings' => Booking::query()->count(),
                'customers' => Customer::query()->count(),
                'inHouse' => $inHouse,
            ],
            'bookingsByMonth' => array_values($byMonth),
            'statusBreakdown' => collect(Booking::STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'count' => (int) ($statusCounts[$status] ?? 0),
            ])->values(),
            'merchants' => $merchants,
            'upcoming' => $upcoming,
            'recentBookings' => $recent,
        ]);
    }

    public function merchants(): JsonResponse
    {
        $merchants = User::merchants()
            ->withCount(['units', 'bookings'])
            ->orderBy('merchant_name')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->uuid,
                    'name' => $user->merchant_name,
                    'contactName' => $this->personName($user),
                    'email' => $user->email,
                    'contactNumber' => $user->contact_number,
                    'address' => $user->address,
                    'rooms' => (int) $user->units_count,
                    'bookings' => (int) $user->bookings_count,
                    'plan' => null,
                    'createdAt' => optional($user->created_at)->toDateString(),
                ];
            });

        return response()->json([
            'merchants' => $merchants,
        ]);
    }

    public function merchant(string $merchant, SubscriptionBillingService $billing): JsonResponse
    {
        $user = User::merchants()
            ->where('uuid', $merchant)
            ->first();

        if (! $user) {
            return response()->json(['message' => 'Merchant was not found.'], 404);
        }

        try {
            $invoice = $billing->calculateNextInvoice($user->uuid);
            $user->refresh();
        } catch (ValidationException) {
            $invoice = null;
        }

        $rooms = Unit::query()
            ->with('property:uuid,property_name')
            ->where('user_uuid', $user->uuid)
            ->orderBy('name')
            ->get();

        $bookings = Booking::query()
            ->with('unit:uuid,name')
            ->where('user_uuid', $user->uuid)
            ->orderByDesc('check_in')
            ->limit(100)
            ->get();

        $payments = SubscriptionPayment::query()
            ->where('user_uuid', $user->uuid)
            ->orderByDesc('paid_at')
            ->orderByDesc('due_on')
            ->get();

        $openBookings = Booking::query()
            ->where('user_uuid', $user->uuid)
            ->where('status', '!=', Booking::STATUS_CANCELLED);

        return response()->json([
            'merchant' => [
                'id' => $user->uuid,
                'name' => $user->merchant_name,
                'contactName' => $this->personName($user),
                'email' => $user->email,
                'contactNumber' => $user->contact_number,
                'address' => $user->address,
                'accountStatus' => $user->account_status ?: 'active',
                'emailVerified' => $user->email_verified_at !== null,
                'registeredOn' => optional($user->created_at)->toDateString(),
            ],
            'counts' => [
                'properties' => Property::query()->where('user_uuid', $user->uuid)->count(),
                'rooms' => $rooms->count(),
                'activeRooms' => $rooms->where('status', Unit::STATUS_ACTIVE)->count(),
                'bookings' => Booking::query()->where('user_uuid', $user->uuid)->count(),
                'guests' => (int) (clone $openBookings)->distinct()->count('guest_name'),
                'revenue' => round((float) (clone $openBookings)->sum('total_price'), 2),
            ],
            'properties' => Property::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('property_name')
                ->get()
                ->map(fn (Property $property) => [
                    'id' => $property->uuid,
                    'name' => $property->property_name,
                    'email' => $property->contact_email,
                    'phone' => $property->phone,
                    'address' => $property->address,
                    'currency' => $property->currency,
                    'checkIn' => $property->check_in_time,
                    'checkOut' => $property->check_out_time,
                ])
                ->values(),
            'rooms' => $rooms->map(fn (Unit $unit) => [
                'id' => $unit->uuid,
                'name' => $unit->name,
                'property' => $unit->property?->property_name,
                'type' => $unit->type,
                'status' => $unit->status,
                'maxGuests' => $unit->max_guests,
                'bedrooms' => $unit->bedrooms,
                'beds' => $unit->beds,
                'pricePerNight' => $unit->price_per_night,
                'currency' => $unit->currency,
            ])->values(),
            'bookings' => $bookings->map(fn (Booking $booking) => [
                'id' => $booking->uuid,
                'reference' => $booking->reference,
                'room' => $booking->unit?->name,
                'guest' => $booking->guest_name,
                'email' => $booking->guest_email,
                'phone' => $booking->guest_phone,
                'checkIn' => $this->dateString($booking->check_in),
                'checkOut' => $this->dateString($booking->check_out),
                'status' => $booking->status,
                'total' => $booking->total_price,
                'currency' => $booking->currency,
            ])->values(),
            'team' => TeamMember::query()
                ->where('owner_user_uuid', $user->uuid)
                ->orderBy('name')
                ->get()
                ->map(fn (TeamMember $member) => [
                    'id' => $member->uuid,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->role,
                ])
                ->values(),
            'portals' => BookingPortalConnection::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('portal_key')
                ->get()
                ->map(fn (BookingPortalConnection $portal) => [
                    'id' => $portal->uuid,
                    'key' => $portal->portal_key,
                    'connected' => (bool) $portal->is_connected,
                    'active' => (bool) $portal->is_active,
                    'listings' => (int) $portal->listing_count,
                    'lastSyncedAt' => optional($portal->last_synced_at)->toIso8601String(),
                ])
                ->values(),
            'billing' => $invoice,
            'payments' => $payments->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->uuid,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'dueOn' => $this->dateString($payment->due_on),
                'paidAt' => optional($payment->paid_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function bookings(): JsonResponse
    {
        $bookings = Booking::query()
            ->with(['user:uuid,merchant_name', 'unit:uuid,name'])
            ->orderByDesc('check_in')
            ->limit(200)
            ->get()
            ->map(fn (Booking $booking) => $this->bookingPayload($booking));

        return response()->json([
            'bookings' => $bookings,
        ]);
    }

    public function calendars(Request $request): JsonResponse
    {
        [$from, $to] = $this->calendarRange($request);

        $bookings = Booking::query()
            ->where('status', '!=', Booking::STATUS_CANCELLED)
            ->whereDate('check_in', '<', $to)
            ->whereDate('check_out', '>', $from)
            ->orderBy('check_in')
            ->get()
            ->groupBy('unit_uuid');

        $blocks = UnitDateBlock::query()
            ->whereDate('start_date', '<', $to)
            ->whereDate('end_date', '>', $from)
            ->orderBy('start_date')
            ->get()
            ->groupBy('unit_uuid');

        $merchants = Unit::query()
            ->with('user:uuid,merchant_name')
            ->orderBy('name')
            ->get()
            ->groupBy('user_uuid')
            ->map(function ($rooms, $userUuid) use ($bookings, $blocks) {
                $first = $rooms->first();

                return [
                    'id' => (string) $userUuid,
                    'name' => $first?->user?->merchant_name ?: 'Merchant',
                    'rooms' => $rooms->sortBy('name')->values()->map(function (Unit $unit) use ($bookings, $blocks) {
                        return [
                            'id' => $unit->uuid,
                            'name' => $unit->name,
                            'type' => $unit->type,
                            'bedrooms' => $unit->bedrooms,
                            'beds' => $unit->beds,
                            'maxGuests' => $unit->max_guests,
                            'status' => $unit->status,
                            'bookings' => $bookings->get($unit->uuid, collect())->map(function (Booking $booking) {
                                return [
                                    'id' => $booking->uuid,
                                    'reference' => $booking->reference,
                                    'guest' => $booking->guest_name,
                                    'checkIn' => $this->dateString($booking->check_in),
                                    'checkOut' => $this->dateString($booking->check_out),
                                    'status' => $booking->status,
                                ];
                            })->values(),
                            'blocks' => $blocks->get($unit->uuid, collect())->map(function (UnitDateBlock $block) {
                                return [
                                    'id' => $block->uuid,
                                    'label' => $block->label,
                                    'startDate' => $this->dateString($block->start_date),
                                    'endDate' => $this->dateString($block->end_date),
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            })
            ->sortBy('name')
            ->values();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'merchants' => $merchants,
        ]);
    }

    public function users(): JsonResponse
    {
        $users = User::query()
            ->orderBy('merchant_name')
            ->get()
            ->map(fn (User $user) => $this->userPayload($user));

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->uuid,
            'name' => $this->personName($user),
            'merchantName' => $user->merchant_name,
            'email' => $user->email,
            'contactNumber' => $user->contact_number,
            'role' => $user->is_platform_admin ? 'Platform admin' : ($user->is_marketing ? 'Marketing' : 'Merchant'),
            'createdAt' => optional($user->created_at)->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(Booking $booking): array
    {
        return [
            'id' => $booking->uuid,
            'reference' => $booking->reference,
            'merchant' => $booking->user?->merchant_name,
            'room' => $booking->unit?->name,
            'guest' => $booking->guest_name,
            'email' => $booking->guest_email,
            'phone' => $booking->guest_phone,
            'checkIn' => $this->dateString($booking->check_in),
            'checkOut' => $this->dateString($booking->check_out),
            'status' => $booking->status,
            'total' => $booking->total_price,
            'currency' => $booking->currency,
        ];
    }

    private function personName(User $user): string
    {
        $name = trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])));

        return $name !== '' ? $name : ($user->merchant_name ?: $user->email);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function calendarRange(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        if ($this->isYmd($from) && $this->isYmd($to) && $from < $to) {
            $start = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();
            if ($start->diffInDays($end) <= 62) {
                return [$start->toDateString(), $end->toDateString()];
            }
        }

        $start = now()->startOfDay();

        return [$start->toDateString(), $start->copy()->addDays(21)->toDateString()];
    }

    private function isYmd(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function month(mixed $value): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if (checkdate($month, 1, $year)) {
                return sprintf('%04d-%02d', $year, $month);
            }
        }

        return now()->format('Y-m');
    }
}
