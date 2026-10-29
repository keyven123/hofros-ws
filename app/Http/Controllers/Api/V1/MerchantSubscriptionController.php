<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionFeatureToggle;
use App\Models\SubscriptionCheckout;
use App\Models\SubscriptionPayment;
use App\Services\Payments\PayMongoCheckoutService;
use App\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MerchantSubscriptionController extends Controller
{
    public function __construct(protected SubscriptionBillingService $billing)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()->uuid));
    }

    public function upcomingInvoice(Request $request): JsonResponse
    {
        return response()->json($this->billing->calculateNextInvoice($request->user()->uuid));
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = SubscriptionPayment::query()
            ->where('user_uuid', $request->user()->uuid)
            ->orderByDesc('paid_at')
            ->orderByDesc('due_on')
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->uuid,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'dueOn' => $payment->due_on?->toDateString(),
                'paidAt' => $payment->paid_at?->toIso8601String(),
                'status' => 'paid',
            ])
            ->values();

        return response()->json(['payments' => $payments]);
    }

    public function pay(Request $request): JsonResponse
    {
        return response()->json($this->billing->pay($request->user()));
    }

    public function checkout(Request $request, PayMongoCheckoutService $paymongo): JsonResponse
    {
        $invoice = $this->billing->calculateNextInvoice($request->user()->uuid);
        if ((float) $invoice['total'] <= 0) {
            return response()->json(['message' => 'There is no amount to pay.'], 422);
        }

        $checkout = SubscriptionCheckout::query()->create([
            'user_uuid' => $request->user()->uuid,
            'amount' => $invoice['total'],
            'currency' => $invoice['currency'],
            'due_on' => $invoice['dueOn'],
            'status' => 'pending',
        ]);

        $lines = [];
        foreach ($invoice['items'] as $item) {
            if ((float) $item['amount'] <= 0) {
                continue;
            }
            $name = $item['type'] === 'rooms'
                ? $item['quantity'].' active '.($item['quantity'] === 1 ? 'room' : 'rooms')
                : (string) $item['label'];
            $lines[] = [
                'name' => $name,
                'amount' => (float) $item['amount'],
                'quantity' => 1,
            ];
        }

        try {
            $session = $paymongo->create(
                $checkout->uuid,
                (float) $invoice['total'],
                (string) $invoice['currency'],
                $lines,
                'Hofros subscription due '.$invoice['dueOn'],
            );
        } catch (RuntimeException $exception) {
            $checkout->delete();

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $checkout->update(['checkout_session_id' => $session['id']]);

        return response()->json([
            'checkoutUrl' => $session['checkout_url'],
            'reference' => $checkout->uuid,
        ]);
    }

    public function confirmCheckout(Request $request, PayMongoCheckoutService $paymongo): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'uuid'],
        ]);

        $checkout = SubscriptionCheckout::query()
            ->where('uuid', $validated['reference'])
            ->where('user_uuid', $request->user()->uuid)
            ->first();

        if (! $checkout) {
            return response()->json(['message' => 'Checkout was not found.'], 404);
        }

        if ($checkout->status !== 'paid') {
            if (! $paymongo->isPaid((string) $checkout->checkout_session_id)) {
                return response()->json([
                    'paid' => false,
                    'message' => 'Payment is not completed yet.',
                ], 422);
            }

            $checkout->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            $this->billing->pay($request->user());
        }

        return response()->json([
            'paid' => true,
            'invoice' => $this->billing->calculateNextInvoice($request->user()->uuid),
        ]);
    }

    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature_key' => ['required', 'string', 'max:64'],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->billing->toggleFeature(
            $request->user()->uuid,
            $validated['feature_key'],
            (bool) $validated['enabled'],
        );

        return response()->json($this->payload($request->user()->uuid));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $merchantId): array
    {
        $toggles = SubscriptionFeatureToggle::query()
            ->where('user_uuid', $merchantId)
            ->get()
            ->keyBy('feature_key');

        $features = SubscriptionFeature::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (SubscriptionFeature $feature) use ($toggles) {
                $toggle = $toggles->get($feature->key);

                return [
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'monthlyPrice' => (float) $feature->monthly_price,
                    'currency' => $feature->currency,
                    'enabled' => (bool) ($toggle?->enabled),
                ];
            });

        try {
            $invoice = $this->billing->calculateNextInvoice($merchantId);
        } catch (ValidationException) {
            $invoice = null;
        }

        return [
            'features' => $features,
            'invoice' => $invoice,
        ];
    }
}
