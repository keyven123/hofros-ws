<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerPortalIntegration;
use App\Services\Ticketoc\TicketocStayConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class AdminPartnerPortalController extends Controller
{
    public function __construct(private TicketocStayConnectionService $stay) {}
    public function index(): JsonResponse
    {
        $rows = PartnerPortalIntegration::ensureCatalog();

        return response()->json([
            'integrations' => $rows->map(fn (PartnerPortalIntegration $row) => $row->toAdminArray())->values(),
        ]);
    }

    public function update(Request $request, string $portalKey): JsonResponse
    {
        $allowed = array_keys(PartnerPortalIntegration::CATALOG);
        if (! in_array($portalKey, $allowed, true)) {
            return response()->json(['message' => 'Unknown partner portal.'], 404);
        }

        $validated = $request->validate([
            'apiBaseUrl' => ['nullable', 'string', 'max:255', 'url'],
            'isEnabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:120'],
            'stayPartnerKey' => ['nullable', 'string', 'max:255'],
        ]);

        $row = PartnerPortalIntegration::forPortal($portalKey);
        if (! $row) {
            return response()->json(['message' => 'Partner portal not found.'], 404);
        }

        $updates = [];
        if (array_key_exists('apiBaseUrl', $validated)) {
            $url = $validated['apiBaseUrl'];
            $updates['api_base_url'] = is_string($url) && trim($url) !== ''
                ? rtrim(trim($url), '/')
                : null;
        }
        if (array_key_exists('isEnabled', $validated)) {
            $updates['is_enabled'] = (bool) $validated['isEnabled'];
        }
        if (array_key_exists('name', $validated) && filled($validated['name'])) {
            $updates['name'] = trim((string) $validated['name']);
        }
        if ($portalKey === 'ticketoc') {
            $this->applyStayPartnerKey($row, $validated['stayPartnerKey'] ?? null);
        }

        if ($updates !== []) {
            $row->update($updates);
        } elseif ($row->isDirty()) {
            $row->save();
        }

        return response()->json([
            'message' => $row->name.' API settings saved.',
            'integration' => $row->fresh()->toAdminArray(),
            'integrations' => PartnerPortalIntegration::ensureCatalog()
                ->map(fn (PartnerPortalIntegration $item) => $item->toAdminArray())
                ->values(),
        ]);
    }

    public function updateMany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'integrations' => ['required', 'array', 'min:1'],
            'integrations.*.portalKey' => ['required', 'string', Rule::in(array_keys(PartnerPortalIntegration::CATALOG))],
            'integrations.*.apiBaseUrl' => ['nullable', 'string', 'max:255'],
            'integrations.*.isEnabled' => ['sometimes', 'boolean'],
            'integrations.*.stayPartnerKey' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($validated['integrations'] as $item) {
            $row = PartnerPortalIntegration::forPortal($item['portalKey']);
            if (! $row) {
                continue;
            }

            $url = $item['apiBaseUrl'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                if (! filter_var(trim($url), FILTER_VALIDATE_URL)) {
                    return response()->json([
                        'message' => 'Invalid API base URL for '.$row->name.'.',
                        'errors' => ['integrations' => ['Each apiBaseUrl must be a valid URL when provided.']],
                    ], 422);
                }
                $row->api_base_url = rtrim(trim($url), '/');
            } else {
                $row->api_base_url = null;
            }

            if (array_key_exists('isEnabled', $item)) {
                $row->is_enabled = (bool) $item['isEnabled'];
            }

            if ($item['portalKey'] === 'ticketoc') {
                $this->applyStayPartnerKey($row, $item['stayPartnerKey'] ?? null);
            }

            $row->save();
        }

        return response()->json([
            'message' => 'Partner portal API settings saved.',
            'integrations' => PartnerPortalIntegration::ensureCatalog()
                ->map(fn (PartnerPortalIntegration $item) => $item->toAdminArray())
                ->values(),
        ]);
    }

    public function connectStay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'apiBaseUrl' => ['nullable', 'string', 'max:255'],
            'stayPartnerKey' => ['nullable', 'string', 'max:255'],
        ]);

        $row = PartnerPortalIntegration::forPortal('ticketoc');
        if (! $row) {
            return response()->json(['message' => 'Ticketoc partner portal not found.'], 404);
        }

        if (array_key_exists('apiBaseUrl', $validated)) {
            $urlError = $this->assignBaseUrl($row, $validated['apiBaseUrl']);
            if ($urlError !== null) {
                return response()->json(['message' => $urlError], 422);
            }
        }

        $this->applyStayPartnerKey($row, $validated['stayPartnerKey'] ?? null);
        $row->save();

        try {
            $result = $this->stay->connect($row->fresh());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'integration' => $row->fresh()->toAdminArray(),
            ], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'integration' => $row->fresh()->toAdminArray(),
            'integrations' => PartnerPortalIntegration::ensureCatalog()
                ->map(fn (PartnerPortalIntegration $item) => $item->toAdminArray())
                ->values(),
        ]);
    }

    private function applyStayPartnerKey(PartnerPortalIntegration $row, mixed $key): void
    {
        if (! is_string($key) || trim($key) === '') {
            return;
        }

        $row->setOtaPartnerKey($key);
    }

    private function assignBaseUrl(PartnerPortalIntegration $row, mixed $url): ?string
    {
        if (is_string($url) && trim($url) !== '') {
            if (! filter_var(trim($url), FILTER_VALIDATE_URL)) {
                return 'Invalid API base URL for '.$row->name.'.';
            }
            $row->api_base_url = rtrim(trim($url), '/');

            return null;
        }

        $row->api_base_url = null;

        return null;
    }
}
