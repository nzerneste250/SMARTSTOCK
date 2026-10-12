<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(): int
    {
        return DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'POS Security Shop',
            'code' => 'SEC-' . Str::random(12),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function member(User $user, int $shop, string $role): void
    {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shop,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(User $admin, int $shop): int
    {
        $response = $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/products", [
                'name' => 'Rice',
                'sku' => 'SEC-' . Str::random(8),
                'selling_price' => 1500,
            ]);

        $response->assertCreated();

        $id = (int) $response->json('product.id');

        DB::table('inventories')
            ->where('product_id', $id)
            ->update(['quantity_on_hand' => '20.000']);

        return $id;
    }

    private function sale(int $product, string $event): array
    {
        return [
            'event_id' => $event,
            'payment_method' => 'cash',
            'items' => [
                ['product_id' => $product, 'quantity' => '2'],
            ],
        ];
    }

    public function test_cashier_can_record_sale(): void
    {
        $admin = User::factory()->create();
        $cashier = User::factory()->create();
        $shop = $this->makeShop();

        $this->member($admin, $shop, 'shop_admin');
        $this->member($cashier, $shop, 'cashier');

        $product = $this->product($admin, $shop);

        $this->actingAs($cashier)
            ->postJson(
                "/api/shops/{$shop}/sales",
                $this->sale($product, (string) Str::uuid())
            )
            ->assertCreated();

        $this->assertDatabaseHas('sales', [
            'cashier_id' => $cashier->id,
            'shop_id' => $shop,
        ]);
    }

    public function test_customer_cannot_record_sale(): void
    {
        $customer = User::factory()->create();
        $shop = $this->makeShop();

        $this->actingAs($customer)
            ->postJson(
                "/api/shops/{$shop}/sales",
                $this->sale(1, (string) Str::uuid())
            )
            ->assertForbidden();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_shop_admin_cannot_sell_another_shops_product(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();

        $shopA = $this->makeShop();
        $shopB = $this->makeShop();

        $this->member($adminA, $shopA, 'shop_admin');
        $this->member($adminB, $shopB, 'shop_admin');

        $productB = $this->product($adminB, $shopB);

        $this->actingAs($adminA)
            ->postJson(
                "/api/shops/{$shopA}/sales",
                $this->sale($productB, (string) Str::uuid())
            )
            ->assertNotFound();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_same_event_with_changed_quantity_is_rejected(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->member($admin, $shop, 'shop_admin');

        $product = $this->product($admin, $shop);
        $event = (string) Str::uuid();
        $data = $this->sale($product, $event);

        $url = "/api/shops/{$shop}/sales";

        $this->actingAs($admin)
            ->postJson($url, $data)
            ->assertCreated();

        $data['items'][0]['quantity'] = '3';

        $this->actingAs($admin)
            ->postJson($url, $data)
            ->assertStatus(409);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '18.000',
        ]);
    }

    public function test_sale_receipt_is_visible_to_own_shop(): void
    {
        $admin = User::factory()->create();
        $shop = $this->makeShop();
        $this->member($admin, $shop, 'shop_admin');

        $product = $this->product($admin, $shop);

        $response = $this->actingAs($admin)
            ->postJson(
                "/api/shops/{$shop}/sales",
                $this->sale($product, (string) Str::uuid())
            );

        $response->assertCreated();

        $saleId = $response->json('receipt.sale.id');

        $this->actingAs($admin)
            ->getJson("/api/shops/{$shop}/sales/{$saleId}")
            ->assertOk()
            ->assertJsonPath('sale.id', $saleId);
    }
}