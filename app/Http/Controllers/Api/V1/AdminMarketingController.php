<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketingProspect;
use App\Models\MarketingProspectNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminMarketingController extends Controller
{
    public function index(): JsonResponse
    {
        $prospects = MarketingProspect::query()
            ->with(['noteEntries', 'owner:uuid,merchant_name'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (MarketingProspect $prospect) => $this->payload($prospect));

        return response()->json([
            'prospects' => $prospects,
            'accounts' => $this->accounts(),
        ]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ]);

        $name = trim($validated['name']);
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        $user = User::query()->create([
            'merchant_name' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? $parts[0],
            'contact_number' => $this->blank($validated['phone'] ?? null) ?? '',
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
            'is_platform_admin' => false,
            'is_marketing' => true,
            'account_status' => 'active',
        ]);

        return response()->json($this->accountPayload($user), 201);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validated($request);
        $prospect = MarketingProspect::query()->create($attributes);
        if ($attributes['notes']) {
            $prospect->noteEntries()->create(['body' => $attributes['notes']]);
        }

        return response()->json($this->payload($prospect->load(['noteEntries', 'owner:uuid,merchant_name'])), 201);
    }

    public function update(Request $request, string $prospect): JsonResponse
    {
        $record = $this->find($prospect);
        $record->update($this->validated($request));

        return response()->json($this->payload($record));
    }

    public function updateStatus(Request $request, string $prospect): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(MarketingProspect::STATUSES)],
        ]);

        $record = $this->find($prospect);
        $record->update(['status' => $validated['status']]);

        return response()->json($this->payload($record->load('noteEntries')));
    }

    public function storeNote(Request $request, string $prospect): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $record = $this->find($prospect);
        $body = trim($validated['body']);
        $record->noteEntries()->create(['body' => $body]);
        $record->update(['notes' => $body]);

        return response()->json($this->payload($record->load('noteEntries')), 201);
    }

    public function destroy(string $prospect): JsonResponse
    {
        $record = $this->find($prospect);
        $record->noteEntries()->delete();
        $record->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
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
            'ownerUserId' => ['nullable', 'uuid', Rule::exists('users', 'uuid')->where(fn ($query) => $query->where('is_marketing', true))],
        ]);

        return [
            'kind' => $validated['kind'],
            'name' => trim($validated['name']),
            'contact_name' => $this->blank($validated['contactName'] ?? null),
            'email' => $this->blank($validated['email'] ?? null),
            'phone' => $this->blank($validated['phone'] ?? null),
            'location' => $this->blank($validated['location'] ?? null),
            'rooms' => $validated['kind'] === MarketingProspect::KIND_MARKET ? ($validated['rooms'] ?? null) : null,
            'status' => $validated['status'],
            'notes' => $this->blank($validated['notes'] ?? null),
            'owner_user_uuid' => $validated['ownerUserId'] ?? null,
        ];
    }

    private function find(string $id): MarketingProspect
    {
        return MarketingProspect::query()->where('uuid', $id)->firstOrFail();
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
            'ownerId' => $prospect->owner_user_uuid,
            'ownerName' => $prospect->owner?->merchant_name,
            'notes' => $prospect->notes,
            'noteLog' => $prospect->relationLoaded('noteEntries')
                ? $prospect->noteEntries->map(fn (MarketingProspectNote $note) => [
                    'id' => $note->uuid,
                    'body' => $note->body,
                    'createdAt' => optional($note->created_at)->toIso8601String(),
                ])->values()
                : [],
            'updatedAt' => optional($prospect->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        return User::query()
            ->where('is_marketing', true)
            ->withCount('ownedProspects')
            ->orderBy('merchant_name')
            ->get()
            ->map(fn (User $user) => $this->accountPayload($user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(User $user): array
    {
        return [
            'id' => $user->uuid,
            'name' => $user->merchant_name,
            'email' => $user->email,
            'phone' => $user->contact_number,
            'targets' => (int) ($user->owned_prospects_count ?? 0),
        ];
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
