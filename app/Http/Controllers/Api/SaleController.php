<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use App\Services\AccessService;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function store(
        StoreSaleRequest $request,
        int $shopId,
        SaleService $service
    ): JsonResponse {
        $result = $service->create(
            $request->user(),
            $shopId,
            $request->validated()
        );

        return response()->json([
            'message' => $result['replayed']
                ? 'Sale already processed.'
                : 'Sale recorded successfully.',
            'replayed' => $result['replayed'],
            'receipt' => $result['receipt'],
        ], $result['replayed'] ? 200 : 201);
    }

    public function index(Request $request, int $shopId): JsonResponse
    {
        abort_unless(
            app(AccessService::class)->canProcessSales(
                $request->user(),
                $shopId
            ),
            403
        );

        $sales = DB::table('sales')
            ->where('shop_id', $shopId)
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($sales);
    }

    public function show(
        Request $request,
        int $shopId,
        int $saleId
    ): JsonResponse {
        abort_unless(
            app(AccessService::class)->canProcessSales(
                $request->user(),
                $shopId
            ),
            403
        );

        $sale = DB::table('sales')
            ->where('shop_id', $shopId)
            ->where('id', $saleId)
            ->first();

        abort_unless($sale, 404, 'Sale not found.');

        $items = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->orderBy('id')
            ->get();

        return response()->json([
            'sale' => $sale,
            'items' => $items,
        ]);
    }
}