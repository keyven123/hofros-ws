<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $merchant */
        $merchant = $request->user();

        $customers = Customer::query()
            ->whereHas('messages', function ($query) use ($merchant): void {
                $query->where('merchant_user_uuid', $merchant->uuid);
            })
            ->orderBy('full_name')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->uuid,
                'name' => $customer->displayName(),
                'address' => (string) $customer->address,
                'contactNumber' => (string) $customer->contact_number,
                'email' => $customer->email,
            ])
            ->values();

        return response()->json([
            'customers' => $customers,
        ]);
    }
}
