<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductCategoryRequest;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductCategoryController extends Controller
{
    public function index(Request $request, int $shopId): JsonResponse
    {
        $allowed = app(AccessService::class)->hasShopRole(
            $request->user(),
            $shopId,
            ['shop_admin', 'cashier']
        );

        abort_unless($allowed, 403);

        $categories = DB::table('product_categories')
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->orderBy('name')
            ->paginate(25);

        return response()->json($categories);
    }

    public function store(
        StoreProductCategoryRequest $request,
        int $shopId
    ): JsonResponse {
        $data = $request->validated();

        $id = DB::table('product_categories')->insertGetId([
            'shop_id' => $shopId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => DB::table('product_categories')
                ->where('id', $id)
                ->where('shop_id', $shopId)
                ->first(),
        ], 201);
    }
}