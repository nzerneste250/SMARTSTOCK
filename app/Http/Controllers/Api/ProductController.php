<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private function present($product): array
    {
        $value = (array) $product;
        $value['image_url'] = !empty($value['image_path'])
            ? request()->getSchemeAndHttpHost().'/storage/'.ltrim($value['image_path'], '/') : null;
        return $value;
    }
    public function index(Request $request, int $shopId): JsonResponse
    {
        abort_unless(
            app(AccessService::class)->hasShopRole(
                $request->user(),
                $shopId,
                ['shop_admin', 'cashier']
            ),
            403
        );

        $products = DB::table('products')
            ->leftJoin(
                'inventories',
                'inventories.product_id',
                '=',
                'products.id'
            )
            ->where('products.shop_id', $shopId)
            ->select(
                'products.*',
                'inventories.quantity_on_hand',
                'inventories.low_stock_threshold'
            )
            ->orderBy('products.name')
            ->paginate(25);

        $products->getCollection()->transform(fn ($p) => $this->present($p));
        return response()->json($products);
    }

    public function store(
        StoreProductRequest $request,
        int $shopId
    ): JsonResponse {
        $data = $request->validated();
        unset($data['image']);

        $product = DB::transaction(function () use ($shopId, $data, $request) {
            $shop = DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            abort_unless($shop && $shop->status === 'active', 403);

            abort_unless(
                app(AccessService::class)->canManageShop(
                    $request->user(),
                    $shopId
                ),
                403
            );

            // Recheck the category within the transaction.
            if (!empty($data['product_category_id'])) {
                $validCategory = DB::table('product_categories')
                    ->where('id', $data['product_category_id'])
                    ->where('shop_id', $shopId)
                    ->where('is_active', true)
                    ->exists();

                abort_unless($validCategory, 422);
            }

            $imagePath = $request->hasFile('image')
                ? $request->file('image')->store('products/'.$shopId, 'public') : null;
            $id = DB::table('products')->insertGetId([
                'public_id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'product_category_id' => $data['product_category_id'] ?? null,
                'name' => $data['name'],
                'sku' => $data['sku'],
                'barcode' => $data['barcode'] ?? null,
                'description' => $data['description'] ?? null,
                'image_path' => $imagePath,
                'selling_price' => $data['selling_price'],
                'cost_price' => $data['cost_price'] ?? null,
                'unit' => $data['unit'] ?? 'piece',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventories')->insert([
                'shop_id' => $shopId,
                'product_id' => $id,
                'quantity_on_hand' => 0,
                'low_stock_threshold' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('products')
                ->where('id', $id)
                ->where('shop_id', $shopId)
                ->first();
        }, 3);

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $this->present($product),
        ], 201);
    }

    public function update(
        UpdateProductRequest $request,
        int $shopId,
        int $productId
    ): JsonResponse {
        $data = $request->validated();
        unset($data['image']);

        $product = DB::transaction(function () use (
            $request, $shopId, $productId, $data
        ) {
            $shop = DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            abort_unless($shop && $shop->status === 'active', 403);

            abort_unless(
                app(AccessService::class)->canManageShop(
                    $request->user(),
                    $shopId
                ),
                403
            );

            $product = DB::table('products')
                ->where('id', $productId)
                ->where('shop_id', $shopId)
                ->first();

            abort_unless($product, 404);

            if (isset($data['product_category_id'])) {
                $validCategory = DB::table('product_categories')
                    ->where('id', $data['product_category_id'])
                    ->where('shop_id', $shopId)
                    ->where('is_active', true)
                    ->exists();

                abort_unless($validCategory, 422);
            }

            if ($request->hasFile('image')) {
                $newPath = $request->file('image')->store('products/'.$shopId, 'public');
                if ($product->image_path) Storage::disk('public')->delete($product->image_path);
                $data['image_path'] = $newPath;
            }

            if (!empty($data)) {
                $data['updated_at'] = now();

                DB::table('products')
                    ->where('id', $productId)
                    ->where('shop_id', $shopId)
                    ->update($data);
            }

            return DB::table('products')
                ->where('id', $productId)
                ->where('shop_id', $shopId)
                ->first();
        }, 3);

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $this->present($product),
        ]);
    }

    public function removeImage(Request $request, int $shopId, int $productId): JsonResponse
    {
        abort_unless(app(AccessService::class)->canManageShop($request->user(), $shopId), 403);
        $product = DB::table('products')->where('id', $productId)->where('shop_id', $shopId)->first();
        abort_unless($product, 404);
        if ($product->image_path) Storage::disk('public')->delete($product->image_path);
        DB::table('products')->where('id', $productId)->update(['image_path' => null, 'updated_at' => now()]);
        $product->image_path = null;
        return response()->json(['message' => 'Product image removed.', 'product' => $this->present($product)]);
    }
}