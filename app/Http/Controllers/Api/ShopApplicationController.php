<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShopApplicationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopApplicationController extends Controller
{
    public function store(StoreShopApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $existing = DB::table('shop_applications')
            ->where('applicant_user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'You already have a pending application.',
            ], 409);
        }

        $id = DB::table('shop_applications')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'applicant_user_id' => $user->id,
            'business_name' => $data['business_name'],
            'business_type' => $data['business_type'] ?? null,
            'phone' => $data['phone'],
            'province' => $data['province'] ?? null,
            'district' => $data['district'] ?? null,
            'sector' => $data['sector'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Shop application submitted.',
            'application' => DB::table('shop_applications')
                ->where('id', $id)
                ->first(),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $applications = DB::table('shop_applications')
            ->where('applicant_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications,
        ]);
    }
}