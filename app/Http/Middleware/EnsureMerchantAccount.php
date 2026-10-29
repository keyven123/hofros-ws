<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\SubscriptionBillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantAccount
{
    /**
     * Block platform admin accounts from merchant API routes (they must use admin flows).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Sign in with a merchant account.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->is_platform_admin || $user->is_marketing) {
            return response()->json([
                'message' => 'Sign in with a merchant account.',
            ], Response::HTTP_FORBIDDEN);
        }

        app(SubscriptionBillingService::class)->calculateNextInvoice($user->uuid);
        $user->refresh();

        if ($user->account_status === 'suspended' && ! $request->is('api/v1/subscription', 'api/v1/subscription/*')) {
            return response()->json([
                'message' => 'This account is temporarily inactive until the subscription payment is received.',
                'code' => 'account_suspended',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
