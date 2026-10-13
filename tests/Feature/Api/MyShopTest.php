<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyShopTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $name, string $status = 'active'): int
    {
        return (int) DB::table('shops')->insertGetId([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)) . random_int(100, 999),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function member(User $user, int $shopId, string $role = 'shop_admin', string $status = 'active'): void
    {
        DB::table('shop_memberships')->insert([
            'shop_id' => $shopId,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_guest_cannot_list_shops(): void
    {
        $this->getJson('/api/my-shops')->assertUnauthorized();
    }

    public function test_only_active_authorized_memberships_are_returned(): void
    {
        $user = User::factory()->create();
        $allowed = $this->shop('Allowed Shop');
        $inactiveShop = $this->shop('Inactive Shop', 'inactive');
        $revokedShop = $this->shop('Revoked Shop');
        $otherShop = $this->shop('Other Shop');
        $this->member($user, $allowed, 'cashier');
        $this->member($user, $inactiveShop);
        $this->member($user, $revokedShop, 'shop_admin', 'revoked');
        $other = User::factory()->create();
        $this->member($other, $otherShop);

        $this->actingAs($user)->getJson('/api/my-shops')
            ->assertOk()
            ->assertJsonCount(1, 'shops')
            ->assertJsonPath('shops.0.id', $allowed)
            ->assertJsonPath('shops.0.name', 'Allowed Shop')
            ->assertJsonPath('shops.0.status', 'active')
            ->assertJsonPath('shops.0.role', 'cashier')
            ->assertJsonMissingPath('shops.0.email');
    }

    public function test_multiple_authorized_shops_are_listed(): void
    {
        $user = User::factory()->create();
        $first = $this->shop('First Shop');
        $second = $this->shop('Second Shop');
        $this->member($user, $first);
        $this->member($user, $second, 'cashier');

        $this->actingAs($user)->getJson('/api/my-shops')
            ->assertOk()
            ->assertJsonCount(2, 'shops')
            ->assertJsonStructure(['shops' => [['id', 'name', 'status', 'role']]]);
    }
}