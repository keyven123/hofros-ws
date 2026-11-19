<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BookingPortalException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\BookingPortalRepository;
use App\Http\Requests\BookingPortal\SaveDirectWebsiteContentRequest;
use App\Http\Requests\BookingPortal\SaveDirectWebsiteDesignRequest;
use App\Http\Requests\BookingPortal\SaveOtaConnectionRequest;
use App\Http\Requests\BookingPortal\SaveTicketocConnectionRequest;
use App\Http\Requests\BookingPortal\SetDirectWebsiteLiveRequest;
use App\Http\Requests\BookingPortal\UpdatePortalActiveRequest;
use App\Http\Requests\BookingPortal\UploadHeroRequest;
use App\Http\Resources\BookingPortalChannelResource;
use App\Models\BookingPortalConnection;
use App\Services\Ota\OtaPortalConnectionService;
use App\Services\Ticketoc\TicketocConnectionService;
use App\Support\GuestPortalLayout;
use App\Support\GuestPortalUnits;
use App\Support\OtaPortalSchemas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class BookingPortalController extends Controller
{
    public function __construct(
        protected BookingPortalRepository $portalRepository,
        protected TicketocConnectionService $ticketocConnection,
        protected OtaPortalConnectionService $otaConnection,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $userUuid = $request->user()->uuid;
        $merchantSlug = Str::slug((string) ($request->user()->merchant_name ?? '')) ?: 'merchant';

        $order = array_keys($this->portalRepository->portalCatalog());

        $rows = $this->portalRepository
            ->getAllForUser($userUuid)
            ->sortBy(fn (BookingPortalConnection $row) => array_search($row->portal_key, $order, true))
            ->values();

        $channels = $rows->map(
            fn (BookingPortalConnection $row): array => (new BookingPortalChannelResource($row))
                ->additional(['merchantSlug' => $merchantSlug])
                ->resolve($request)
        )->values();

        $connected = $rows->filter(function (BookingPortalConnection $r): bool {
            if ($r->portal_key === BookingPortalRepository::DIRECT_WEBSITE_KEY) {
                return (bool) $r->guest_portal_live;
            }

            return (bool) $r->is_connected;
        });
        $connectedChannels = $connected->count();
        $totalListings = $connected->sum('listing_count');
        $syncIssues = $connected->filter(fn (BookingPortalConnection $r) => $r->has_sync_issue)->count();

        return response()->json([
            'channels' => $channels,
            'summary' => [
                'connectedChannels' => $connectedChannels,
                'totalListings' => $totalListings,
                'syncIssues' => $syncIssues,
            ],
        ]);
    }

    public function directWebsiteSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $row = $this->portalRepository->directWebsiteRow($user->uuid);

        $slug = Str::slug((string) ($user->merchant_name ?? '')) ?: 'merchant';

        return response()->json([
            'merchantSlug' => $slug,
            'merchantName' => $user->merchant_name,
            'headline' => $row->guest_portal_headline,
            'message' => $row->guest_portal_message,
            'pageTitle' => $row->guest_portal_page_title,
            'guestPortalLive' => (bool) $row->guest_portal_live,
            'guestPortalDesignCompleted' => (bool) $row->guest_portal_design_completed,
            'themePreset' => $row->guest_portal_theme_preset ?? 'bold_modern',
            'primaryColor' => $row->guest_portal_primary_color ?? '#1B4F8A',
            'accentColor' => $row->guest_portal_accent_color ?? '#F5A623',
            'heroImageUrl' => $row->guest_portal_hero_image_url ?? $this->defaultGuestPortalHeroUrl(),
            'layout' => GuestPortalLayout::normalize($row->guest_portal_layout),
            'units' => GuestPortalUnits::publicPayloadForUserUuid($user->uuid),
        ]);
    }

    public function uploadDirectWebsiteHero(UploadHeroRequest $request): JsonResponse
    {
        $row = $this->portalRepository->directWebsiteRow($request->user()->uuid);
        $file = $request->file('image');

        $dir = 'guest-portal-heroes/'.$request->user()->uuid;
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg'));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $ext = 'jpg';
        }
        $name = Str::uuid()->toString().'.'.$ext;
        $storedPath = $file->storeAs($dir, $name, 'public');

        $suffix = '/storage/'.str_replace('\\', '/', $storedPath);
        $url = rtrim($request->getSchemeAndHttpHost(), '/').$suffix;

        $row->update(['guest_portal_hero_image_url' => $url]);

        return response()->json([
            'message' => 'Hero photo uploaded.',
            'heroImageUrl' => $url,
        ]);
    }

    public function saveDirectWebsiteContent(SaveDirectWebsiteContentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $row = $this->portalRepository->directWebsiteRow($request->user()->uuid);

        $textUpdates = [];
        if (array_key_exists('headline', $validated) && $validated['headline'] !== null) {
            $textUpdates['guest_portal_headline'] = $validated['headline'];
        }
        if (array_key_exists('message', $validated) && $validated['message'] !== null) {
            $textUpdates['guest_portal_message'] = $validated['message'];
        }
        if (array_key_exists('pageTitle', $validated)) {
            $t = $validated['pageTitle'];
            $textUpdates['guest_portal_page_title'] = is_string($t) && trim($t) !== '' ? trim($t) : null;
        }

        if ($textUpdates !== []) {
            $row->update($textUpdates);
        }

        $this->applyGuestPortalVisualFromValidated($row, $validated);
        $this->applyGuestPortalLayoutFromValidated($row, $validated);

        return response()->json([
            'message' => 'Draft saved.',
            'channel' => (new BookingPortalChannelResource($row->fresh()))->resolve($request),
        ]);
    }

    public function saveDirectWebsiteDesign(SaveDirectWebsiteDesignRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $row = $this->portalRepository->directWebsiteRow($request->user()->uuid);

        $designUpdates = [
            'guest_portal_headline' => $validated['headline'],
            'guest_portal_message' => $validated['message'],
            'guest_portal_design_completed' => true,
        ];
        if (array_key_exists('pageTitle', $validated)) {
            $t = $validated['pageTitle'];
            $designUpdates['guest_portal_page_title'] = is_string($t) && trim($t) !== '' ? trim($t) : null;
        }

        $row->update($designUpdates);

        $row->refresh();
        $this->applyGuestPortalVisualFromValidated($row, $validated);
        $this->applyGuestPortalLayoutFromValidated($row, $validated);

        return response()->json([
            'message' => 'Micro-site design saved. You can go live from the website builder when you are ready.',
            'channel' => (new BookingPortalChannelResource($row->fresh()))->resolve($request),
        ]);
    }

    public function setDirectWebsiteLive(SetDirectWebsiteLiveRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $row = $this->portalRepository->directWebsiteRow($request->user()->uuid);

        if ($validated['live'] && ! $row->guest_portal_design_completed) {
            throw new BookingPortalException('Complete your micro-site design before publishing the guest link.');
        }

        if ($validated['live'] && (! is_string($row->guest_portal_headline) || trim($row->guest_portal_headline) === '')) {
            throw new BookingPortalException('Add a headline in the micro-site designer before going live.');
        }

        $live = $validated['live'];
        $row->update([
            'guest_portal_live' => $live,
            'is_connected' => $live,
            'is_active' => $live,
            'listing_count' => $live ? max(1, (int) $row->listing_count) : 0,
            'last_synced_at' => $live ? Carbon::now() : null,
            'has_sync_issue' => false,
        ]);

        return response()->json([
            'message' => $live
                ? 'Direct portal is now live for guests.'
                : 'Direct portal is in testing mode. Guests no longer see the public page.',
            'channel' => (new BookingPortalChannelResource($row->fresh()))->resolve($request),
        ]);
    }

    public function updateActive(UpdatePortalActiveRequest $request, string $portalKey): JsonResponse
    {
        $this->assertPortalKey($portalKey);

        $validated = $request->validated();
        $row = $this->portalRepository->findOrFail($request->user()->uuid, $portalKey);

        if (! $row->is_connected) {
            throw new BookingPortalException('Connect this channel before enabling it.');
        }

        $row->update(['is_active' => $validated['isActive']]);

        return response()->json((new BookingPortalChannelResource($row->fresh()))->resolve($request));
    }

    public function sync(Request $request, string $portalKey): JsonResponse
    {
        $this->assertPortalKey($portalKey);

        $row = $this->portalRepository->findOrFail($request->user()->uuid, $portalKey);

        if (! $row->is_connected) {
            throw new BookingPortalException('Connect this channel before syncing.');
        }

        if ($portalKey === BookingPortalRepository::TICKETOC_KEY) {
            try {
                $row = $this->ticketocConnection->sync($request->user()->uuid);
            } catch (RuntimeException $e) {
                throw new BookingPortalException($e->getMessage());
            }

            return response()->json([
                'message' => 'Ticketoc sync completed.',
                'channel' => (new BookingPortalChannelResource($row))->resolve($request),
            ]);
        }

        $row->update([
            'last_synced_at' => Carbon::now(),
            'has_sync_issue' => false,
        ]);

        return response()->json([
            'message' => 'Sync queued for '.$portalKey.'.',
            'channel' => (new BookingPortalChannelResource($row->fresh()))->resolve($request),
        ]);
    }

    public function connect(Request $request, string $portalKey): JsonResponse
    {
        $this->assertPortalKey($portalKey);

        if ($portalKey === BookingPortalRepository::DIRECT_WEBSITE_KEY) {
            throw new BookingPortalException('Use “Build a direct website” in the app to open the website builder and copy your guest link.');
        }

        if ($portalKey === BookingPortalRepository::TICKETOC_KEY) {
            throw new BookingPortalException('Open the Ticketoc integration page to enter Ticketoc API credentials.');
        }

        if (OtaPortalSchemas::isSupported($portalKey)) {
            throw new BookingPortalException('Open the '.$portalKey.' integration page to enter OTA API credentials.');
        }

        $row = $this->portalRepository->findOrFail($request->user()->uuid, $portalKey);

        if ($row->is_connected) {
            return response()->json([
                'message' => 'This channel is already connected.',
                'channel' => (new BookingPortalChannelResource($row))->resolve($request),
            ]);
        }

        $row->update([
            'is_connected' => true,
            'is_active' => true,
            'listing_count' => max(1, $row->listing_count),
            'last_synced_at' => Carbon::now(),
            'has_sync_issue' => false,
        ]);

        return response()->json([
            'message' => 'Channel connected. Complete OAuth or API credentials in production.',
            'channel' => (new BookingPortalChannelResource($row->fresh()))->resolve($request),
        ]);
    }

    public function ticketocSettings(Request $request): JsonResponse
    {
        $row = $this->portalRepository->ticketocRow($request->user()->uuid);

        return response()->json([
            'settings' => $this->ticketocConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function ticketocTest(SaveTicketocConnectionRequest $request): JsonResponse
    {
        $credentials = $request->credentials();

        try {
            $result = $this->ticketocConnection->test(
                $request->user()->uuid,
                $credentials['email'],
                $credentials['password'],
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json([
            'message' => 'Ticketoc credentials are valid.',
            'result' => $result,
        ]);
    }

    public function ticketocConnect(SaveTicketocConnectionRequest $request): JsonResponse
    {
        $credentials = $request->credentials();

        try {
            $row = $this->ticketocConnection->connect(
                $request->user()->uuid,
                $credentials['email'],
                $credentials['password'],
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json([
            'message' => 'Captured '.$row->listing_count.' '.($row->listing_count === 1 ? 'room' : 'rooms').' for '.($row->external_organization_name ?: 'your Ticketoc organization').'.',
            'settings' => $this->ticketocConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function ticketocDisconnect(Request $request): JsonResponse
    {
        $row = $this->ticketocConnection->disconnect($request->user()->uuid);

        return response()->json([
            'message' => 'Ticketoc disconnected.',
            'settings' => $this->ticketocConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function otaSettings(Request $request, string $portalKey): JsonResponse
    {
        $this->assertOtaPortalKey($portalKey);
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $row = $this->portalRepository->findOrFail($request->user()->uuid, $portalKey);

        return response()->json([
            'settings' => $this->otaConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function otaTest(SaveOtaConnectionRequest $request, string $portalKey): JsonResponse
    {
        $this->assertOtaPortalKey($portalKey);
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $row = $this->portalRepository->findOrFail($request->user()->uuid, $portalKey);

        try {
            $result = $this->otaConnection->test($portalKey, $request->credentialInput(), $row);
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json([
            'message' => OtaPortalSchemas::for($portalKey)['name'].' credentials look valid.',
            'result' => $result,
        ]);
    }

    public function otaConnect(SaveOtaConnectionRequest $request, string $portalKey): JsonResponse
    {
        $this->assertOtaPortalKey($portalKey);
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);

        try {
            $row = $this->otaConnection->connect($request->user()->uuid, $portalKey, $request->credentialInput());
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json([
            'message' => OtaPortalSchemas::for($portalKey)['name'].' connected. Hofros can sync this channel once partner APIs are live.',
            'settings' => $this->otaConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function otaDisconnect(Request $request, string $portalKey): JsonResponse
    {
        $this->assertOtaPortalKey($portalKey);
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);

        try {
            $row = $this->otaConnection->disconnect($request->user()->uuid, $portalKey);
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json([
            'message' => OtaPortalSchemas::for($portalKey)['name'].' disconnected.',
            'settings' => $this->otaConnection->settingsPayload($row),
            'channel' => (new BookingPortalChannelResource($row))->resolve($request),
        ]);
    }

    public function airbnbAccommodations(Request $request): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $selectedUserId = $request->query('userId');
        $selectedUserId = is_string($selectedUserId) ? trim($selectedUserId) : null;

        try {
            $payload = $this->otaConnection->airbnbAccommodations(
                $request->user()->uuid,
                $selectedUserId !== '' ? $selectedUserId : null
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json($payload);
    }

    public function airbnbReloadAccommodations(Request $request): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $validated = $request->validate([
            'userId' => ['nullable', 'string', 'regex:/^\d{5,20}$/'],
        ]);

        try {
            $payload = $this->otaConnection->reloadAirbnbListings(
                $request->user()->uuid,
                $validated['userId'] ?? null
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json(array_merge([
            'message' => 'Airbnb accommodations reloaded.',
        ], $payload));
    }

    public function airbnbSaveAccommodations(Request $request): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $validated = $request->validate([
            'userId' => ['nullable', 'string', 'regex:/^\d{5,20}$/'],
            'mappings' => ['required', 'array'],
            'mappings.*' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $payload = $this->otaConnection->saveAirbnbMappings(
                $request->user()->uuid,
                $validated['mappings'],
                $validated['userId'] ?? null
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json(array_merge([
            'message' => 'Airbnb accommodation mappings saved.',
        ], $payload));
    }

    public function airbnbAddHost(Request $request): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $validated = $request->validate([
            'userId' => ['required', 'string', 'regex:/^\d{5,20}$/'],
        ]);

        try {
            $payload = $this->otaConnection->addAirbnbHost(
                $request->user()->uuid,
                $validated['userId']
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json(array_merge([
            'message' => 'Airbnb User ID added and listings captured.',
        ], $payload));
    }

    public function airbnbSetHostActive(Request $request, string $hostUserId): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);
        $validated = $request->validate([
            'isActive' => ['required', 'boolean'],
        ]);

        try {
            $payload = $this->otaConnection->setAirbnbHostActive(
                $request->user()->uuid,
                $hostUserId,
                (bool) $validated['isActive']
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json(array_merge([
            'message' => $validated['isActive'] ? 'Airbnb User ID activated.' : 'Airbnb User ID deactivated.',
        ], $payload));
    }

    public function airbnbRemoveHost(Request $request, string $hostUserId): JsonResponse
    {
        $this->portalRepository->ensureRowsForUser($request->user()->uuid);

        try {
            $payload = $this->otaConnection->removeAirbnbHost(
                $request->user()->uuid,
                $hostUserId
            );
        } catch (RuntimeException $e) {
            throw new BookingPortalException($e->getMessage());
        }

        return response()->json(array_merge([
            'message' => 'Airbnb User ID removed.',
        ], $payload));
    }

    public function available(Request $request): JsonResponse
    {
        $order = array_keys($this->portalRepository->portalCatalog());

        $disconnected = $this->portalRepository
            ->getDisconnectedForUser($request->user()->uuid)
            ->sortBy(fn (BookingPortalConnection $row) => array_search($row->portal_key, $order, true))
            ->values()
            ->map(fn (BookingPortalConnection $row): array => (new BookingPortalChannelResource($row))->resolve($request))
            ->values();

        return response()->json(['channels' => $disconnected]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyGuestPortalVisualFromValidated(BookingPortalConnection $row, array $validated): void
    {
        $updates = [];

        if (array_key_exists('themePreset', $validated) && $validated['themePreset'] !== null) {
            $updates['guest_portal_theme_preset'] = $validated['themePreset'];
        }
        if (array_key_exists('primaryColor', $validated) && $validated['primaryColor'] !== null) {
            $updates['guest_portal_primary_color'] = $validated['primaryColor'];
        }
        if (array_key_exists('accentColor', $validated) && $validated['accentColor'] !== null) {
            $updates['guest_portal_accent_color'] = $validated['accentColor'];
        }
        if (array_key_exists('heroImageUrl', $validated) && $validated['heroImageUrl'] !== null) {
            $updates['guest_portal_hero_image_url'] = $validated['heroImageUrl'];
        }

        if ($updates !== []) {
            $row->update($updates);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyGuestPortalLayoutFromValidated(BookingPortalConnection $row, array $validated): void
    {
        if (! array_key_exists('layout', $validated) || ! is_array($validated['layout'])) {
            return;
        }

        $row->refresh();
        $merged = GuestPortalLayout::normalize($row->guest_portal_layout, $validated['layout']);
        $row->update(['guest_portal_layout' => $merged]);
    }

    private function defaultGuestPortalHeroUrl(): string
    {
        return 'https://images.unsplash.com/photo-1540541338287-41700207dee6?auto=format&fit=crop&w=1600&q=80';
    }

    private function assertPortalKey(string $portalKey): void
    {
        if (! array_key_exists($portalKey, $this->portalRepository->portalCatalog())) {
            abort(404, 'Unknown portal.');
        }
    }

    private function assertOtaPortalKey(string $portalKey): void
    {
        $this->assertPortalKey($portalKey);
        if (! OtaPortalSchemas::isSupported($portalKey)) {
            abort(404, 'Unknown OTA portal.');
        }
    }
}
