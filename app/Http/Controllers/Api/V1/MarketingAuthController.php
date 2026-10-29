<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\UserRepository;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class MarketingAuthController extends Controller
{
    public function __construct(protected UserRepository $userRepository)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->userRepository->findByEmail($validated['email']);

        if (! $user || ! $user->is_marketing || ! Hash::check($validated['password'], $user->password)) {
            throw new InvalidCredentialsException();
        }

        $token = $user->createToken('marketing_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Marketing login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'name' => $user->merchant_name,
                'email' => $user->email,
            ],
        ]);
    }
}
