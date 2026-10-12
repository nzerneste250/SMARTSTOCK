<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $status = 'active'): int
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

    private function member(
        User $user,
        int $shopId,
        string $role
    ): void {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shopId,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_categories(): void
    {
        $this->getJson('/api/shops/1/categories')
            ->assertUnauthorized();

        $this->postJson('/api/shops/1/categories', [
            'name' => 'Food',
        ])->assertUnauthorized();
    }

    public function test_shop_admin_can_create_category(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->shop();
        $this->member($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/categories", [
                'name' => 'Food',
                'description' => 'Food products',
            ])
            ->assertCreated()
            ->assertJsonPath('category.name', 'Food');

        $this->assertDatabaseHas('product_categories', [
            'shop_id' => $shopId,
            'name' => 'Food',
        ]);
    }

    public function test_cashier_can_view_but_cannot_create_categories(): void
    {
        $cashier = User::factory()->create();
        $shopId = $this->shop();
        $this->member($cashier, $shopId, 'cashier');

        $this->actingAs($cashier)
            ->getJson("/api/shops/{$shopId}/categories")
            ->assertOk();

        $this->actingAs($cashier)
            ->postJson("/api/shops/{$shopId}/categories", [
                'name' => 'Drinks',
            ])
            ->assertForbidden();
    }

    public function test_admin_cannot_access_another_shops_categories(): void
    {
        $admin = User::factory()->create();
        $shopA = $this->shop();
        $shopB = $this->shop();
        $this->member($admin, $shopA, 'shop_admin');

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shopB}/categories")
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopB}/categories", [
                'name' => 'Food',
            ])
            ->assertForbidden();
    }

    public function test_duplicate_category_in_same_shop_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->shop();
        $this->member($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/categories", [
                'name' => 'Food',
            ])
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/categories", [
                'name' => 'Food',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('product_categories', 1);
    }

    public function test_suspended_shop_cannot_access_categories(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->shop('suspended');
        $this->member($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shopId}/categories")
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/categories", [
                'name' => 'Food',
            ])
            ->assertForbidden();
    }
}