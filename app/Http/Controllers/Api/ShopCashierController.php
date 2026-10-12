<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashierRequest;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopCashierController extends Controller
{
    private function authorizeShop(Request $request, int $shopId): void
    {
        abort_unless(
            app(AccessService::class)->canManageShop(
                $request->user(),
                $shopId
            ),
            403
        );
    }

    private function audit(
        int $shopId,
        int $actorId,
        int $targetId,
        string $action
    ): void {
        DB::table('audit_logs')->insert([
            'event_id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'user_id' => $actorId,
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => (string) $targetId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function index(Request $request, int $shopId): JsonResponse
    {
        $this->authorizeShop($request, $shopId);

        $cashiers = DB::table('shop_memberships')
            ->join('users', 'users.id', '=', 'shop_memberships.user_id')
            ->where('shop_memberships.shop_id', $shopId)
            ->where('shop_memberships.role', 'cashier')
            ->where('shop_memberships.status', 'active')
            ->select('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->paginate(20);

        return response()->json($cashiers);
    }

    public function store(
        StoreCashierRequest $request,
        int $shopId
    ): JsonResponse {
        $email = $request->validated('email');

        return DB::transaction(function () use ($request, $shopId, $email) {
            // Serialize staff changes for this shop.
            $shop = DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            abort_unless($shop && $shop->status === 'active', 403);

            $this->authorizeShop($request, $shopId);

            $target = DB::table('users')
                ->where('email', $email)
                ->first();

            abort_unless($target, 422);

            if ($target->platform_role === 'super_admin') {
                return response()->json([
                    'message' => 'This account cannot be assigned as cashier.',
                ], 422);
            }

            $membership = DB::table('shop_memberships')
                ->where('shop_id', $shopId)
                ->where('user_id', $target->id)
                ->first();

            if ($membership && $membership->status === 'active') {
                return response()->json([
                    'message' => 'This user already belongs to this shop.',
                ], 409);
            }

            if ($membership && $membership->role !== 'cashier') {
                return response()->json([
                    'message' => 'An existing role prevents this assignment.',
                ], 409);
            }

            if ($membership) {
                DB::table('shop_memberships')
                    ->where('id', $membership->id)
                    ->update([
                        'status' => 'active',
                        'invited_by' => $request->user()->id,
                        'joined_at' => now(),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('shop_memberships')->insert([
                    'shop_id' => $shopId,
                    'user_id' => $target->id,
                    'role' => 'cashier',
                    'status' => 'active',
                    'invited_by' => $request->user()->id,
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit(
                $shopId,
                $request->user()->id,
                $target->id,
                'cashier.assigned'
            );

            return response()->json([
                'message' => 'Cashier assigned successfully.',
                'user' => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'email' => $target->email,
                ],
            ], 201);
        }, 3);
    }

    public function destroy(
        Request $request,
        int $shopId,
        int $userId
    ): JsonResponse {
        return DB::transaction(function () use (
            $request, $shopId, $userId
        ) {
            DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            $this->authorizeShop($request, $shopId);

            $membership = DB::table('shop_memberships')
                ->where('shop_id', $shopId)
                ->where('user_id', $userId)
                ->where('role', 'cashier')
                ->where('status', 'active')
                ->first();

            if (!$membership) {
                return response()->json([
                    'message' => 'Active cashier membership not found.',
                ], 404);
            }

            DB::table('shop_memberships')
                ->where('id', $membership->id)
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);

            $this->audit(
                $shopId,
                $request->user()->id,
                $userId,
                'cashier.revoked'
            );

            return response()->json([
                'message' => 'Cashier access revoked.',
            ]);
        }, 3);
    }
}