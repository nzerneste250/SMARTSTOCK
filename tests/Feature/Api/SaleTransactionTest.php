<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function setupShop(): array
    {
        $admin = User::factory()->create();

        $shop = DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'POS Test Shop',
            'code' => 'POS-' . Str::random(12),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shop_memberships')->insert([
            'shop_id' => $shop,
            'user_id' => $admin->id,
            'role' => 'shop_admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/products", [
                'name' => 'Rice 1kg',
                'sku' => 'POS-RICE-001',
                'selling_price' => 1500,
            ]);

        $response->assertCreated();

        $product = (int) $response->json('product.id');

        DB::table('inventories')
            ->where('product_id', $product)
            ->update(['quantity_on_hand' => '20.000']);

        return [$admin, $shop, $product];
    }

    private function saleData(int $product, string $event): array
    {
        return [
            'event_id' => $event,
            'payment_method' => 'cash',
            'items' => [
                [
                    'product_id' => $product,
                    'quantity' => '2',
                ],
            ],
        ];
    }

    public function test_sale_deducts_stock_and_creates_receipt(): void
    {
        [$admin, $shop, $product] = $this->setupShop();

        $response = $this->actingAs($admin)
            ->postJson(
                "/api/shops/{$shop}/sales",
                $this->saleData($product, (string) Str::uuid())
            );

        $response->assertCreated()
            ->assertJsonPath('replayed', false);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '18.000',
        ]);

        $this->assertDatabaseHas('sales', [
            'shop_id' => $shop,
            'total' => '3000.00',
            'status' => 'completed',
        ]);

        $this->assertDatabaseCount('sale_items', 1);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product,
            'movement_type' => 'sale',
            'quantity_change' => '-2.000',
        ]);
    }

    public function test_duplicate_sale_does_not_deduct_stock_twice(): void
    {
        [$admin, $shop, $product] = $this->setupShop();

        $data = $this->saleData(
            $product,
            (string) Str::uuid()
        );

        $url = "/api/shops/{$shop}/sales";

        $this->actingAs($admin)
            ->postJson($url, $data)
            ->assertCreated();

        $this->actingAs($admin)
            ->postJson($url, $data)
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '18.000',
        ]);

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_items', 1);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_insufficient_stock_rolls_back_sale(): void
    {
        [$admin, $shop, $product] = $this->setupShop();

        $data = $this->saleData(
            $product,
            (string) Str::uuid()
        );

        $data['items'][0]['quantity'] = '25';

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/sales", $data)
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product,
            'quantity_on_hand' => '20.000',
        ]);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_multi_product_sale_rolls_back_if_one_product_has_no_stock(): void
    {
        [$admin, $shop, $productA] = $this->setupShop();

        $response = $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/products", [
                'name' => 'Sugar 1kg',
                'sku' => 'POS-SUGAR-002',
                'selling_price' => 2000,
            ]);

        $response->assertCreated();

        $productB = (int) $response->json('product.id');

        // Product A has 20 units, Product B has zero.
        $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/sales", [
                'event_id' => (string) Str::uuid(),
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $productA,
                        'quantity' => '2',
                    ],
                    [
                        'product_id' => $productB,
                        'quantity' => '1',
                    ],
                ],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('inventories', [
            'product_id' => $productA,
            'quantity_on_hand' => '20.000',
        ]);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $productB,
            'quantity_on_hand' => '0.000',
        ]);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_sale_uses_server_price_not_client_price(): void
    {
        [$admin, $shop, $product] = $this->setupShop();

        $data = $this->saleData(
            $product,
            (string) Str::uuid()
        );

        // The app attempts to supply an unauthorized lower price.
        $data['items'][0]['unit_price'] = '1.00';
        $data['total'] = '2.00';

        $this->actingAs($admin)
            ->postJson("/api/shops/{$shop}/sales", $data)
            ->assertCreated();

        // Actual price is 1500 x 2 = 3000.
        $this->assertDatabaseHas('sales', [
            'shop_id' => $shop,
            'total' => '3000.00',
        ]);

        $this->assertDatabaseHas('sale_items', [
            'product_id' => $product,
            'unit_price' => '1500.00',
            'line_total' => '3000.00',
        ]);
    }
}