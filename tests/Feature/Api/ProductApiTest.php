<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(): int
    {
        return DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Test Shop',
            'code' => 'SS-' . Str::random(12),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignRole(User $user, int $shopId, string $role): void
    {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shopId,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_create_product_with_zero_inventory(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($admin, $shopId, 'shop_admin');

        $response = $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/products", [
                'name' => 'Rice 1kg',
                'sku' => 'RICE-001',
                'selling_price' => 1500,
            ]);

        $response->assertCreated();

        $productId = $response->json('product.id');

        $this->assertDatabaseHas('inventories', [
            'shop_id' => $shopId,
            'product_id' => $productId,
            'quantity_on_hand' => 0,
        ]);
    }

    public function test_cashier_cannot_create_product(): void
    {
        $cashier = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($cashier, $shopId, 'cashier');

        $this->actingAs($cashier)
            ->postJson("/api/shops/{$shopId}/products", [
                'name' => 'Sugar',
                'sku' => 'SUGAR-001',
                'selling_price' => 1000,
            ])
            ->assertForbidden();
    }

    public function test_negative_price_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($admin, $shopId, 'shop_admin');

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/products", [
                'name' => 'Milk',
                'sku' => 'MILK-001',
                'selling_price' => -100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['selling_price']);
    }

    public function test_category_from_another_shop_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $this->assignRole($admin, $shopA, 'shop_admin');

        $categoryId = DB::table('product_categories')->insertGetId([
            'shop_id' => $shopB,
            'name' => 'Drinks',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopA}/products", [
                'name' => 'Juice',
                'sku' => 'JUICE-001',
                'selling_price' => 1000,
                'product_category_id' => $categoryId,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_category_id']);
    }

    public function test_duplicate_sku_in_one_shop_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($admin, $shopId, 'shop_admin');

        $product = [
            'name' => 'Rice',
            'sku' => 'RICE-001',
            'selling_price' => 1500,
        ];

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/products", $product)
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/products", $product)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);

        $this->assertDatabaseCount('products', 1);
    }

    public function test_admin_cannot_update_product_in_another_shop(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();

        $shopA = $this->makeShop();
        $shopB = $this->makeShop();

        $this->assignRole($adminA, $shopA, 'shop_admin');
        $this->assignRole($adminB, $shopB, 'shop_admin');

        $response = $this->actingAs($adminB)
            ->postJson("/api/shops/{$shopB}/products", [
                'name' => 'Rice',
                'sku' => 'RICE-001',
                'selling_price' => 1500,
            ]);

        $response->assertCreated();

        $id = $response->json('product.id');

        $this->actingAs($adminA)
            ->patchJson("/api/shops/{$shopA}/products/{$id}", [
                'selling_price' => 10,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'selling_price' => 1500,
        ]);
    }
}