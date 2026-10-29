<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_platform_admin) {
            return response()->json([
                'message' => 'Sign in with a platform administrator account.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
