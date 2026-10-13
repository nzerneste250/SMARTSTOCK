<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewShopApplicationRequest;
use App\Services\ShopApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->platform_role === 'super_admin',
            403
        );

        $query = DB::table('shop_applications')
            ->leftJoin('users', 'users.id', '=', 'shop_applications.applicant_user_id')
            ->leftJoin('shops', 'shops.id', '=', 'shop_applications.shop_id')
            ->select(
                'shop_applications.*',
                'users.name as applicant_name',
                'users.email as applicant_email',
                'shops.name as shop_name',
                'shops.code as shop_code'
            );

        if ($request->query('status')) {
            $status = $request->validate([
                'status' => ['in:pending,approved,rejected'],
            ])['status'];

            $query->where('status', $status);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(20)
        );
    }

    public function review(
        ReviewShopApplicationRequest $request,
        int $applicationId,
        ShopApprovalService $service
    ): JsonResponse {
        $data = $request->validated();

        $result = $service->review(
            $applicationId,
            $request->user(),
            $data['decision'],
            $data['review_notes'] ?? null
        );

        return response()->json([
            'message' => 'Application reviewed successfully.',
            'result' => $result,
        ]);
    }
}