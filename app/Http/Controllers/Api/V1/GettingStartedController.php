<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GettingStartedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GettingStartedController extends Controller
{
    public function __construct(protected GettingStartedService $gettingStarted)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->gettingStarted->payloadFor($request->user()));
    }

    public function dismiss(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->getting_started_dismissed_at === null) {
            $user->forceFill([
                'getting_started_dismissed_at' => now(),
            ])->save();
        }

        return response()->json($this->gettingStarted->payloadFor($user->fresh()));
    }
}
