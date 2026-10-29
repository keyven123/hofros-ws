<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Repositories\PublicDirectPortalRepository;
use App\Http\Requests\Portal\StoreGuestMessageRequest;
use App\Models\Customer;
use App\Models\GuestMessage;
use App\Models\User;
use App\Support\GuestPortalLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalMessageController extends Controller
{
    public function __construct(protected PublicDirectPortalRepository $portalRepository)
    {
    }

    public function customerIndex(Request $request, string $slug): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $merchant = $this->merchantAcceptingMessages($slug);

        $this->markRead($merchant->uuid, $customer->uuid, 'merchant');

        return response()->json([
            'messages' => $this->messagesFor($merchant->uuid, $customer->uuid),
        ]);
    }

    public function customerStore(StoreGuestMessageRequest $request, string $slug): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $merchant = $this->merchantAcceptingMessages($slug);

        GuestMessage::query()->create([
            'merchant_user_uuid' => $merchant->uuid,
            'customer_uuid' => $customer->uuid,
            'sender' => 'customer',
            'body' => trim($request->validated()['body']),
        ]);

        return response()->json([
            'messages' => $this->messagesFor($merchant->uuid, $customer->uuid),
        ], Response::HTTP_CREATED);
    }

    public function merchantIndex(Request $request): JsonResponse
    {
        /** @var User $merchant */
        $merchant = $request->user();

        $rows = GuestMessage::query()
            ->where('merchant_user_uuid', $merchant->uuid)
            ->with('customer')
            ->orderBy('created_at')
            ->get()
            ->groupBy('customer_uuid');

        $threads = $rows->map(function ($messages) {
            /** @var GuestMessage $latest */
            $latest = $messages->last();
            $customer = $latest->customer;

            return [
                'id' => $customer?->uuid,
                'name' => $customer?->displayName() ?: 'Customer',
                'email' => $customer?->email ?? '',
                'unread' => $messages->contains(fn (GuestMessage $message) => $message->sender === 'customer' && $message->read_at === null),
                'messages' => $messages->map(fn (GuestMessage $message) => $this->present($message))->values(),
            ];
        })->sortByDesc(fn (array $thread) => $thread['messages']->last()['createdAt'] ?? '')->values();

        return response()->json([
            'threads' => $threads,
        ]);
    }

    public function merchantReply(StoreGuestMessageRequest $request, string $customerUuid): JsonResponse
    {
        /** @var User $merchant */
        $merchant = $request->user();
        $customer = Customer::query()->where('uuid', $customerUuid)->first();

        if ($customer === null) {
            return response()->json(['message' => 'Customer not found.'], Response::HTTP_NOT_FOUND);
        }

        $hasThread = GuestMessage::query()
            ->where('merchant_user_uuid', $merchant->uuid)
            ->where('customer_uuid', $customer->uuid)
            ->exists();

        if (! $hasThread) {
            return response()->json(['message' => 'No conversation with this customer yet.'], Response::HTTP_NOT_FOUND);
        }

        GuestMessage::query()->create([
            'merchant_user_uuid' => $merchant->uuid,
            'customer_uuid' => $customer->uuid,
            'sender' => 'merchant',
            'body' => trim($request->validated()['body']),
        ]);
        $this->markRead($merchant->uuid, $customer->uuid, 'customer');

        return response()->json([
            'messages' => $this->messagesFor($merchant->uuid, $customer->uuid),
        ], Response::HTTP_CREATED);
    }

    public function merchantRead(Request $request, string $customerUuid): JsonResponse
    {
        /** @var User $merchant */
        $merchant = $request->user();
        $this->markRead($merchant->uuid, $customerUuid, 'customer');

        return response()->json([
            'messages' => $this->messagesFor($merchant->uuid, $customerUuid),
        ]);
    }

    private function merchantAcceptingMessages(string $slug): User
    {
        $resolved = $this->portalRepository->resolveBySlugForMessages($slug);
        $layout = GuestPortalLayout::normalize($resolved['connection']->guest_portal_layout);

        if (! $layout['allowGuestMessages']) {
            abort(response()->json([
                'message' => 'This property is not accepting messages.',
            ], Response::HTTP_FORBIDDEN));
        }

        return $resolved['user'];
    }

    private function markRead(string $merchantUuid, string $customerUuid, string $sender): void
    {
        GuestMessage::query()
            ->where('merchant_user_uuid', $merchantUuid)
            ->where('customer_uuid', $customerUuid)
            ->where('sender', $sender)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesFor(string $merchantUuid, string $customerUuid): array
    {
        return GuestMessage::query()
            ->where('merchant_user_uuid', $merchantUuid)
            ->where('customer_uuid', $customerUuid)
            ->orderBy('created_at')
            ->get()
            ->map(fn (GuestMessage $message) => $this->present($message))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, from: string, text: string, at: string, createdAt: string}
     */
    private function present(GuestMessage $message): array
    {
        return [
            'id' => $message->uuid,
            'from' => $message->sender,
            'text' => $message->body,
            'at' => $message->created_at?->timezone(config('app.timezone'))->format('M j, g:i A') ?? '',
            'createdAt' => $message->created_at?->toIso8601String() ?? '',
        ];
    }
}
