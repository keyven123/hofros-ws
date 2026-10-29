<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class CustomerAuthController extends Controller
{
    public function register(CustomerRegisterRequest $request): JsonResponse
    {
        $customer = Customer::query()->create($request->validated());
        $token = $customer->createToken('customer_token')->plainTextToken;

        return response()->json([
            'message' => 'Customer account created.',
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => $this->customerPayload($customer),
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $customer = Customer::query()->where('email', $validated['email'])->first();

        if (! $customer || ! Hash::check($validated['password'], $customer->password)) {
            throw new InvalidCredentialsException();
        }

        $token = $customer->createToken('customer_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'customer' => $this->customerPayload($customer),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json([
            'customer' => $this->customerPayload($customer),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    /**
     * @return array{uuid: string, name: string, address: string, contactNumber: string, email: string}
     */
    private function customerPayload(Customer $customer): array
    {
        return [
            'uuid' => $customer->uuid,
            'name' => $customer->displayName(),
            'address' => $customer->address,
            'contactNumber' => $customer->contact_number,
            'email' => $customer->email,
        ];
    }
}
