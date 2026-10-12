<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccessService
{
    public function isSuperAdmin(User $user): bool
    {
        return $user->platform_role === 'super_admin';
    }

    public function isCustomer(User $user): bool
    {
        return $user->platform_role === 'customer';
    }

    public function hasShopRole(
        User $user,
        int $shopId,
        array $allowedRoles
    ): bool {
        return DB::table('shop_memberships')
            ->where('user_id', $user->id)
            ->where('shop_id', $shopId)
            ->where('status', 'active')
            ->whereIn('role', $allowedRoles)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('shops')
                    ->whereColumn('shops.id', 'shop_memberships.shop_id')
                    ->where('shops.status', 'active');
            })
            ->exists();
    }

    public function canManageShop(User $user, int $shopId): bool
    {
        return $this->hasShopRole($user, $shopId, ['shop_admin']);
    }

    public function canProcessSales(User $user, int $shopId): bool
    {
        return $this->hasShopRole(
            $user,
            $shopId,
            ['shop_admin', 'cashier']
        );
    }
}
