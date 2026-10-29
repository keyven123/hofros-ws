<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingProspect;
use App\Models\MarketingProspectNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarketingWorkspaceController extends Controller
{
    public function home(Request $request): JsonResponse
    {
        $prospects = $this->ownedQuery($request)->get();

        return response()->json([
            'account' => [
                'name' => $request->user()->merchant_name,
                'email' => $request->user()->email,
            ],
            'counts' => [
                'total' => $prospects->count(),
                'markets' => $prospects->where('kind', MarketingProspect::KIND_MARKET)->count(),
                'clients' => $prospects->where('kind', MarketingProspect::KIND_CLIENT)->count(),
                'contacted' => $prospects->where('status', MarketingProspect::STATUS_CONTACTED)->count(),
                'interested' => $prospects->where('status', MarketingProspect::STATUS_INTERESTED)->count(),
                'onboarded' => $prospects->where('status', MarketingProspect::STATUS_ONBOARDED)->count(),
            ],
            'prospects' => $prospects->map(fn (MarketingProspect $prospect) => $this->payload($prospect))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MarketingProspect::KINDS)],
            'name' => ['required', 'string', 'max:160'],
            'contactName' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'location' => ['nullable', 'string', 'max:255'],
            'rooms' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'status' => ['required', Rule::in(MarketingProspect::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $notes = $this->blank($validated['notes'] ?? null);
        $prospect = MarketingProspect::query()->create([
            'owner_user_uuid' => $request->user()->uuid,
            'kind' => $validated['kind'],
            'name' => trim($validated['name']),
            'contact_name' => $this->blank($validated['contactName'] ?? null),
            'email' => $this->blank($validated['email'] ?? null),
            'phone' => $this->blank($validated['phone'] ?? null),
            'location' => $this->blank($validated['location'] ?? null),
            'rooms' => $validated['kind'] === MarketingProspect::KIND_MARKET ? ($validated['rooms'] ?? null) : null,
            'status' => $validated['status'],
            'notes' => $notes,
        ]);

        if ($notes) {
            $prospect->noteEntries()->create(['body' => $notes]);
        }

        return response()->json($this->payload($prospect->load('noteEntries')), 201);
    }

    public function updateStatus(Request $request, string $prospect): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(MarketingProspect::STATUSES)],
        ]);

        $record = $this->owned($request, $prospect);
        $record->update(['status' => $validated['status']]);

        return response()->json($this->payload($record->load('noteEntries')));
    }

    public function storeNote(Request $request, string $prospect): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $record = $this->owned($request, $prospect);
        $body = trim($validated['body']);
        $record->noteEntries()->create(['body' => $body]);
        $record->update(['notes' => $body]);

        return response()->json($this->payload($record->load('noteEntries')), 201);
    }

    private function ownedQuery(Request $request)
    {
        return MarketingProspect::query()
            ->with('noteEntries')
            ->where('owner_user_uuid', $request->user()->uuid)
            ->orderByDesc('updated_at');
    }

    private function owned(Request $request, string $id): MarketingProspect
    {
        return MarketingProspect::query()
            ->where('uuid', $id)
            ->where('owner_user_uuid', $request->user()->uuid)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(MarketingProspect $prospect): array
    {
        return [
            'id' => $prospect->uuid,
            'kind' => $prospect->kind,
            'name' => $prospect->name,
            'contactName' => $prospect->contact_name,
            'email' => $prospect->email,
            'phone' => $prospect->phone,
            'location' => $prospect->location,
            'rooms' => $prospect->rooms,
            'status' => $prospect->status,
            'notes' => $prospect->notes,
            'noteLog' => $prospect->relationLoaded('noteEntries')
                ? $prospect->noteEntries->map(fn (MarketingProspectNote $note) => [
                    'id' => $note->uuid,
                    'body' => $note->body,
                    'createdAt' => optional($note->created_at)->toIso8601String(),
                ])->values()
                : [],
        ];
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
