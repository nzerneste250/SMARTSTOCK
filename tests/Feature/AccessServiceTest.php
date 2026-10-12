<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_roles_are_identified_correctly(): void
    {
        $access = app(AccessService::class);

        $superAdmin = User::factory()->make([
            'platform_role' => 'super_admin',
        ]);

        $customer = User::factory()->make([
            'platform_role' => 'customer',
        ]);

        $this->assertTrue($access->isSuperAdmin($superAdmin));
        $this->assertFalse($access->isSuperAdmin($customer));
        $this->assertTrue($access->isCustomer($customer));
    }

    public function test_shop_roles_and_cross_shop_isolation(): void
    {
        $access = app(AccessService::class);
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $outsider = User::factory()->create();

        $shopA = DB::table('shops')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Shop A',
            'code' => 'SHOP-A',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shopB = DB::table('shops')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Shop B',
            'code' => 'SHOP-B',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shop_memberships')->insert([
            [
                'shop_id' => $shopA,
                'user_id' => $admin->id,
                'role' => 'shop_admin',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'shop_id' => $shopA,
                'user_id' => $cashier->id,
                'role' => 'cashier',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertTrue($access->canManageShop($admin, $shopA));
        $this->assertTrue($access->canProcessSales($admin, $shopA));

        $this->assertFalse($access->canManageShop($cashier, $shopA));
        $this->assertTrue($access->canProcessSales($cashier, $shopA));

        $this->assertFalse($access->canProcessSales($outsider, $shopA));
        $this->assertFalse($access->canManageShop($admin, $shopB));
        $this->assertFalse($access->canProcessSales($cashier, $shopB));

        DB::table('shops')
            ->where('id', $shopA)
            ->update(['status' => 'suspended']);

        $this->assertFalse($access->canManageShop($admin, $shopA));
        $this->assertFalse($access->canProcessSales($cashier, $shopA));
    }
}