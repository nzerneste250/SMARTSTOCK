<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustStockRequest;
use App\Services\AccessService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
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

        $items = DB::table('inventories')
            ->join('products', function ($join) {
                $join->on('products.id', '=', 'inventories.product_id')
                    ->on('products.shop_id', '=', 'inventories.shop_id');
            })
            ->where('inventories.shop_id', $shopId)
            ->select(
                'inventories.product_id',
                'products.name',
                'products.sku',
                'inventories.quantity_on_hand',
                'inventories.low_stock_threshold'
            )
            ->paginate(25);

        return response()->json($items);
    }

    public function adjust(
        AdjustStockRequest $request,
        int $shopId,
        int $productId,
        InventoryService $service
    ): JsonResponse {
        $result = $service->adjust(
            $request->user(),
            $shopId,
            $productId,
            $request->validated()
        );

        return response()->json([
            'message' => $result['replayed']
                ? 'Stock operation already processed.'
                : 'Stock updated successfully.',
            'result' => $result,
        ]);
    }

    public function history(
        Request $request,
        int $shopId,
        int $productId
    ): JsonResponse {
        abort_unless(
            app(AccessService::class)->canManageShop(
                $request->user(),
                $shopId
            ),
            403
        );

        $productExists = DB::table('products')
            ->where('id', $productId)
            ->where('shop_id', $shopId)
            ->exists();

        abort_unless($productExists, 404);

        $movements = DB::table('stock_movements')
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($movements);
    }
}