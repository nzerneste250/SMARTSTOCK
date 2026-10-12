<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(): int
    {
        return DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Inventory Test Shop',
            'code' => 'INV-' . Str::random(12),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignAdmin(User $user, int $shopId): void
    {
        $this->assignRole($user, $shopId, 'shop_admin');
    }

    private function assignRole(
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

    private function makeProduct(User $admin, int $shopId): int
    {
        $response = $this->actingAs($admin)
            ->postJson("/api/shops/{$shopId}/products", [
                'name' => 'Rice 1kg',
                'sku' => 'RICE-' . Str::random(8),
                'selling_price' => 1500,
            ]);

        $response->assertCreated();

        return (int) $response->json('product.id');
    }

    private function adjustment(
        string $eventId,
        string $type,
        string $direction,
        string $quantity
    ): array {
        return [
            'event_id' => $eventId,
            'movement_type' => $type,
            'direction' => $direction,
            'quantity' => $quantity,
            'notes' => 'Inventory test adjustment',
        ];
    }

    public function test_admin_can_receive_stock(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $this->actingAs($admin)->postJson(
            "/api/shops/{$shop}/products/{$product}/stock-adjustments",
            $this->adjustment(
                (string) Str::uuid(),
                'stock_received',
                'increase',
                '10.500'
            )
        )->assertOk()
         ->assertJsonPath('result.replayed', false);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '10.500',
        ]);

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_admin_can_remove_existing_stock(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $url = "/api/shops/{$shop}/products/{$product}/stock-adjustments";

        $this->actingAs($admin)->postJson($url,
            $this->adjustment(
                (string) Str::uuid(),
                'stock_received',
                'increase',
                '10'
            )
        )->assertOk();

        $this->actingAs($admin)->postJson($url,
            $this->adjustment(
                (string) Str::uuid(),
                'stock_removed',
                'decrease',
                '3'
            )
        )->assertOk();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '7.000',
        ]);

        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_stock_cannot_become_negative(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $this->actingAs($admin)->postJson(
            "/api/shops/{$shop}/products/{$product}/stock-adjustments",
            $this->adjustment(
                (string) Str::uuid(),
                'stock_removed',
                'decrease',
                '5'
            )
        )->assertUnprocessable();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => 0,
        ]);

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_repeated_event_does_not_double_stock(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $url = "/api/shops/{$shop}/products/{$product}/stock-adjustments";

        $data = $this->adjustment(
            (string) Str::uuid(),
            'stock_received',
            'increase',
            '8'
        );

        $this->actingAs($admin)->postJson($url, $data)
            ->assertOk()
            ->assertJsonPath('result.replayed', false);

        $this->actingAs($admin)->postJson($url, $data)
            ->assertOk()
            ->assertJsonPath('result.replayed', true);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '8.000',
        ]);

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_cashier_cannot_adjust_stock(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shop = $this->makeShop();

        $this->assignAdmin($admin, $shop);
        $this->assignRole($cashier, $shop, 'cashier');

        $product = $this->makeProduct($admin, $shop);

        $this->actingAs($cashier)->postJson(
            "/api/shops/{$shop}/products/{$product}/stock-adjustments",
            $this->adjustment(
                (string) Str::uuid(),
                'stock_received',
                'increase',
                '10'
            )
        )->assertForbidden();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_reused_event_with_different_data_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $url = "/api/shops/{$shop}/products/{$product}/stock-adjustments";

        $data = $this->adjustment(
            (string) Str::uuid(),
            'stock_received',
            'increase',
            '5'
        );

        $this->actingAs($admin)->postJson($url, $data)
            ->assertOk();

        $changed = $data;
        $changed['quantity'] = '50';

        $this->actingAs($admin)->postJson($url, $changed)
            ->assertStatus(409);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '5.000',
        ]);

        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_receiving_stock_cannot_decrease_inventory(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();

        $this->assignAdmin($admin, $shop);
        $product = $this->makeProduct($admin, $shop);

        $this->actingAs($admin)->postJson(
            "/api/shops/{$shop}/products/{$product}/stock-adjustments",
            $this->adjustment(
                (string) Str::uuid(),
                'stock_received',
                'decrease',
                '5'
            )
        )->assertUnprocessable();

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_admin_cannot_adjust_another_shops_inventory(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();

        $shopA = $this->makeShop();
        $shopB = $this->makeShop();

        $this->assignAdmin($adminA, $shopA);
        $this->assignAdmin($adminB, $shopB);

        $productB = $this->makeProduct($adminB, $shopB);

        $this->actingAs($adminA)->postJson(
            "/api/shops/{$shopA}/products/{$productB}/stock-adjustments",
            $this->adjustment(
                (string) Str::uuid(),
                'stock_received',
                'increase',
                '20'
            )
        )->assertNotFound();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $productB,
            'quantity_on_hand' => 0,
        ]);

        $this->assertDatabaseCount('stock_movements', 0);
    }
}