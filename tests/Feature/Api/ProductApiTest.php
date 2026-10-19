<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function pngFile(string $name = 'product.png', ?string $content = null): UploadedFile
    {
        $content ??= base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent($name, $content);
    }

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

    public function test_admin_can_upload_replace_and_remove_product_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($admin, $shopId, 'shop_admin');
        $product = $this->actingAs($admin)->postJson("/api/shops/{$shopId}/products", [
            'name' => 'Tea', 'sku' => 'TEA-001', 'selling_price' => 1000,
        ])->assertCreated()->json('product');

        $first = $this->pngFile('tea.png');
        $this->actingAs($admin)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopId}/products/{$product['id']}", [
            '_method' => 'PATCH', 'image' => $first,
        ])->assertOk();
        $path = DB::table('products')->where('id', $product['id'])->value('image_path');
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        $second = $this->pngFile('tea-new.png');
        $this->actingAs($admin)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopId}/products/{$product['id']}", [
            '_method' => 'PATCH', 'image' => $second,
        ])->assertOk();
        $replacement = DB::table('products')->where('id', $product['id'])->value('image_path');
        $this->assertNotSame($path, $replacement);
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($replacement);

        $this->actingAs($admin)->deleteJson("/api/shops/{$shopId}/products/{$product['id']}/image")->assertOk();
        Storage::disk('public')->assertMissing($replacement);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'image_path' => null]);
    }

    public function test_cashier_and_other_shop_cannot_upload_product_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $otherAdmin = User::factory()->create();
        $cashier = User::factory()->create();
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $this->assignRole($admin, $shopA, 'shop_admin');
        $this->assignRole($otherAdmin, $shopB, 'shop_admin');
        $this->assignRole($cashier, $shopA, 'cashier');
        $product = $this->actingAs($admin)->postJson("/api/shops/{$shopA}/products", [
            'name' => 'Soap', 'sku' => 'SOAP-001', 'selling_price' => 500,
        ])->assertCreated()->json('product');

        $file = $this->pngFile('soap.png');
        $this->actingAs($cashier)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopA}/products/{$product['id']}", ['_method' => 'PATCH', 'image' => $file])->assertForbidden();
        $this->actingAs($otherAdmin)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopB}/products/{$product['id']}", ['_method' => 'PATCH', 'image' => $this->pngFile('other.png')])->assertNotFound();
    }

    public function test_invalid_and_oversized_product_images_are_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $shopId = $this->makeShop();
        $this->assignRole($admin, $shopId, 'shop_admin');
        $product = $this->actingAs($admin)->postJson("/api/shops/{$shopId}/products", [
            'name' => 'Flour', 'sku' => 'FLOUR-001', 'selling_price' => 900,
        ])->assertCreated()->json('product');
        $this->actingAs($admin)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopId}/products/{$product['id']}", ['_method' => 'PATCH', 'image' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors(['image']);
        $oversized = $this->pngFile('large.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=') . str_repeat('x', 2 * 1024 * 1024));
        $this->actingAs($admin)->withHeaders(['Accept' => 'application/json'])->post("/api/shops/{$shopId}/products/{$product['id']}", ['_method' => 'PATCH', 'image' => $oversized])->assertUnprocessable()->assertJsonValidationErrors(['image']);
    }

    public function test_barcode_is_unique_per_shop_and_unchanged_edit_is_allowed(): void
    {
        $admin = User::factory()->create();
        $shopA = $this->makeShop();
        $shopB = $this->makeShop();
        $this->assignRole($admin, $shopA, 'shop_admin');
        $this->assignRole($admin, $shopB, 'shop_admin');
        $product = ['name' => 'Coffee', 'sku' => 'COFFEE-001', 'barcode' => '1234567890123', 'selling_price' => 1200];
        $id = $this->actingAs($admin)->postJson("/api/shops/{$shopA}/products", $product)->assertCreated()->json('product.id');
        $this->actingAs($admin)->postJson("/api/shops/{$shopA}/products", ['name' => 'Coffee 2', 'sku' => 'COFFEE-002', 'barcode' => $product['barcode'], 'selling_price' => 1300])->assertUnprocessable()->assertJsonValidationErrors(['barcode']);
        $this->actingAs($admin)->postJson("/api/shops/{$shopB}/products", ['name' => 'Coffee B', 'sku' => 'COFFEE-B', 'barcode' => $product['barcode'], 'selling_price' => 1300])->assertCreated();
        $this->actingAs($admin)->patchJson("/api/shops/{$shopA}/products/{$id}", ['barcode' => $product['barcode']])->assertOk();
    }
}