<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MyShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shops = DB::table('shop_memberships')
            ->join('shops', 'shops.id', '=', 'shop_memberships.shop_id')
            ->where('shop_memberships.user_id', $request->user()->id)
            ->where('shop_memberships.status', 'active')
            ->whereIn('shop_memberships.role', ['shop_admin', 'cashier'])
            ->where('shops.status', 'active')
            ->orderBy('shops.name')
            ->get([
                'shops.id',
                'shops.name',
                'shops.status',
                'shop_memberships.role',
            ]);

        return response()->json(['shops' => $shops]);
    }
}