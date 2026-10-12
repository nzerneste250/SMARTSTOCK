<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\AccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShopCashierTest extends TestCase
{
    use RefreshDatabase;

    private function createShop(string $status = 'active'): int
    {
        return DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Test Shop',
            'code' => 'SS-' . Str::random(12),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMembership(
        User $user,
        int $shopId,
        string $role,
        string $status = 'active'
    ): void {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shopId,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_cannot_manage_cashiers(): void
    {
        $this->getJson('/api/shops/1/cashiers')
            ->assertUnauthorized();

        $this->postJson('/api/shops/1/cashiers', [
            'email' => 'test@example.com',
        ])->assertUnauthorized();

        $this->deleteJson('/api/shops/1/cashiers/1')
            ->assertUnauthorized();
    }

    public function test_shop_admin_can_assign_cashier(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shopId = $this->createShop();

        $this->addMembership($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $cashier->email,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('shop_memberships', [
            'shop_id' => $shopId,
            'user_id' => $cashier->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);

        $this->assertTrue(
            app(AccessService::class)->canProcessSales($cashier, $shopId)
        );

        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $shopId,
            'action' => 'cashier.assigned',
        ]);
    }

    public function test_cashier_cannot_assign_another_cashier(): void
    {
        $cashier = User::factory()->create();
        $target = User::factory()->create();
        $shopId = $this->createShop();

        $this->addMembership($cashier, $shopId, 'cashier');

        $this->actingAs($cashier)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $target->email,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('shop_memberships', [
            'shop_id' => $shopId,
            'user_id' => $target->id,
        ]);
    }

    public function test_shop_admin_cannot_manage_another_shop(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        $shopA = $this->createShop();
        $shopB = $this->createShop();

        $this->addMembership($admin, $shopA, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopB}/cashiers", [
                'email' => $target->email,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shopB}/cashiers")
            ->assertForbidden();

        $this->actingAs($admin)
            ->deleteJson("/api/shops/{$shopB}/cashiers/{$target->id}")
            ->assertForbidden();
    }

    public function test_duplicate_cashier_assignment_is_rejected(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shopId = $this->createShop();

        $this->addMembership($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $cashier->email,
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $cashier->email,
            ])
            ->assertStatus(409);

        $this->assertSame(
            1,
            DB::table('shop_memberships')
                ->where('shop_id', $shopId)
                ->where('user_id', $cashier->id)
                ->count()
        );
    }

    public function test_shop_admin_can_list_only_own_cashiers(): void
    {
        $admin = User::factory()->create();
        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();

        $shopA = $this->createShop();
        $shopB = $this->createShop();

        $this->addMembership($admin, $shopA, 'shop_admin');
        $this->addMembership($cashierA, $shopA, 'cashier');
        $this->addMembership($cashierB, $shopB, 'cashier');

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shopA}/cashiers")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cashierA->id);
    }

    public function test_removing_cashier_revokes_shop_permission(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shopId = $this->createShop();

        $this->addMembership($admin, $shopId, 'shop_admin');
        $this->addMembership($cashier, $shopId, 'cashier');

        $this->assertTrue(
            app(AccessService::class)->canProcessSales($cashier, $shopId)
        );

        $this->actingAs($admin)
            ->deleteJson("/api/shops/{$shopId}/cashiers/{$cashier->id}")
            ->assertOk();

        $this->assertDatabaseHas('shop_memberships', [
            'shop_id' => $shopId,
            'user_id' => $cashier->id,
            'status' => 'inactive',
        ]);

        $this->assertFalse(
            app(AccessService::class)->canProcessSales($cashier, $shopId)
        );

        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $shopId,
            'action' => 'cashier.revoked',
        ]);
    }

    public function test_suspended_shop_cannot_manage_cashiers(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shopId = $this->createShop('suspended');

        $this->addMembership($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $cashier->email,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shopId}/cashiers")
            ->assertForbidden();
    }

    public function test_shop_admin_cannot_assign_super_admin_as_cashier(): void
    {
        $admin = User::factory()->create();
        $superAdmin = User::factory()->create([
            'platform_role' => 'super_admin',
        ]);
        $shopId = $this->createShop();

        $this->addMembership($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/cashiers", [
                'email' => $superAdmin->email,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('shop_memberships', [
            'shop_id' => $shopId,
            'user_id' => $superAdmin->id,
        ]);
    }
}