<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\TeamMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => (new UserResource($request->user()))->resolve($request),
        ]);
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        $previousEmail = $user->email;
        $validated = $request->validated();
        $validated['middle_name'] = $validated['middle_name'] ?? null;
        $validated['address'] = $validated['address'] ?? null;

        $user->fill($validated);
        $user->save();

        $name = trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])));

        TeamMember::query()
            ->where('owner_user_uuid', $user->uuid)
            ->where('email', $previousEmail)
            ->update([
                'email' => $user->email,
                'name' => $name !== '' ? $name : $user->merchant_name,
            ]);

        return response()->json([
            'message' => 'Profile saved.',
            'user' => (new UserResource($user->fresh()))->resolve($request),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $user->password = $request->validated('password');
        $user->save();

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }
}
