<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function show(Request $request, int $shopId): JsonResponse
    {
        abort_unless(app(AccessService::class)->hasShopRole(
            $request->user(), $shopId, ['shop_admin', 'cashier']
        ), 403);

        $todaySales = DB::table('sales')
            ->where('shop_id', $shopId)
            ->where('status', 'completed')
            ->whereDate('sold_at', today());

        $inventory = DB::table('inventories')
            ->where('shop_id', $shopId);

        $recentSales = DB::table('sales')
            ->where('shop_id', $shopId)
            ->where('status', 'completed')
            ->orderByDesc('sold_at')
            ->limit(5)
            ->get(['id', 'receipt_number', 'total', 'payment_method', 'sold_at']);

        return response()->json([
            'date' => today()->toDateString(),
            'sales' => [
                'total' => (string) $todaySales->sum('total'),
                'count' => $todaySales->count(),
            ],
            'products' => [
                'active' => DB::table('products')->where('shop_id', $shopId)->where('is_active', true)->count(),
            ],
            'inventory' => [
                'low_stock' => (clone $inventory)->whereColumn('quantity_on_hand', '<=', 'low_stock_threshold')->where('quantity_on_hand', '>', 0)->count(),
                'out_of_stock' => (clone $inventory)->where('quantity_on_hand', '<=', 0)->count(),
                'units' => (string) (clone $inventory)->sum('quantity_on_hand'),
            ],
            'recent_sales' => $recentSales,
        ]);
    }
}