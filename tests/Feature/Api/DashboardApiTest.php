<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_read_empty_dashboard_summary(): void
    {
        $user = User::factory()->create(['name' => 'Shop Admin']);
        $shopId = $this->createShop('Dashboard Shop');
        $this->addMembership($shopId, $user->id, 'shop_admin');

        $response = $this->actingAs($user)->getJson("/api/shops/{$shopId}/dashboard");

        $response->assertOk()->assertJsonPath('sales.total', '0')->assertJsonPath('sales.count', 0)->assertJsonPath('products.active', 0)->assertJsonPath('inventory.low_stock', 0)->assertJsonPath('inventory.out_of_stock', 0)->assertJsonPath('recent_sales', []);
    }

    public function test_member_cannot_read_another_shop_dashboard(): void
    {
        $user = User::factory()->create();
        $shopId = $this->createShop('Private Dashboard Shop');

        $this->actingAs($user)->getJson("/api/shops/{$shopId}/dashboard")->assertForbidden();
    }

    private function createShop(string $name): int
    {
        return DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => $name,
            'code' => strtoupper(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addMembership(int $shopId, int $userId, string $role): void
    {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shopId, 'user_id' => $userId, 'role' => $role,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}