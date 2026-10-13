<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function applicationData(): array
    {
        return [
            'business_name' => 'SmartStock Test Shop',
            'business_type' => 'Retail',
            'phone' => '0780000000',
            'province' => 'Northern',
            'district' => 'Musanze',
            'sector' => 'Muhoza',
            'address' => 'Musanze, Rwanda',
        ];
    }

    private function submitApplication(User $user): int
    {
        $response = $this->actingAs($user)
            ->postJson('/api/shop-applications', $this->applicationData());

        $response->assertCreated();

        return (int) $response->json('application.id');
    }

    public function test_guest_cannot_submit_application(): void
    {
        $this->postJson(
            '/api/shop-applications',
            $this->applicationData()
        )->assertUnauthorized();
    }

    public function test_customer_can_submit_application(): void
    {
        $customer = User::factory()->create();
        $id = $this->submitApplication($customer);

        $this->assertDatabaseHas('shop_applications', [
            'id' => $id,
            'applicant_user_id' => $customer->id,
            'status' => 'pending',
        ]);
    }

    public function test_invalid_application_data_is_rejected(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->postJson('/api/shop-applications', [
                'business_name' => '',
                'phone' => str_repeat('1', 31),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['business_name', 'phone']);
    }

    public function test_duplicate_pending_application_is_rejected(): void
    {
        $customer = User::factory()->create();

        $this->submitApplication($customer);

        $this->actingAs($customer)
            ->postJson('/api/shop-applications', $this->applicationData())
            ->assertStatus(409);

        $this->assertDatabaseCount('shop_applications', 1);
    }

    public function test_customer_cannot_approve_application(): void
    {
        $applicant = User::factory()->create();
        $customer = User::factory()->create();

        $id = $this->submitApplication($applicant);

        $this->actingAs($customer)
            ->postJson("/api/admin/shop-applications/{$id}/review", [
                'decision' => 'approved',
            ])->assertForbidden();

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_super_admin_can_approve_application(): void
    {
        $applicant = User::factory()->create();

        $admin = User::factory()->create([
            'platform_role' => 'super_admin',
        ]);

        $id = $this->submitApplication($applicant);

        $this->actingAs($admin)
            ->postJson("/api/admin/shop-applications/{$id}/review", [
                'decision' => 'approved',
                'review_notes' => 'Business verified.',
            ])
            ->assertOk()
            ->assertJsonPath('result.status', 'approved');

        $this->assertDatabaseHas('shop_applications', [
            'id' => $id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        $shop = DB::table('shops')->first();

        $this->assertNotNull($shop);
        $this->assertSame('active', $shop->status);

        $this->assertDatabaseHas('shop_memberships', [
            'shop_id' => $shop->id,
            'user_id' => $applicant->id,
            'role' => 'shop_admin',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'shop_application.approved',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/shop-applications/{$id}/review", [
                'decision' => 'approved',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('shops', 1);
    }

    public function test_super_admin_can_reject_application(): void
    {
        $applicant = User::factory()->create();

        $admin = User::factory()->create([
            'platform_role' => 'super_admin',
        ]);

        $id = $this->submitApplication($applicant);

        $this->actingAs($admin)
            ->postJson("/api/admin/shop-applications/{$id}/review", [
                'decision' => 'rejected',
                'review_notes' => 'Information incomplete.',
            ])
            ->assertOk()
            ->assertJsonPath('result.status', 'rejected');

        $this->assertDatabaseHas('shop_applications', [
            'id' => $id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseCount('shops', 0);
        $this->assertDatabaseCount('shop_memberships', 0);
    }

    public function test_customers_see_only_their_own_applications(): void
    {
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();

        $this->submitApplication($customerA);
        $this->submitApplication($customerB);

        $this->actingAs($customerA)
            ->getJson('/api/shop-applications/mine')
            ->assertOk()
            ->assertJsonCount(1, 'applications');
    }

    public function test_customer_can_retrieve_application_status_and_review_notes(): void
    {
        $customer = User::factory()->create();
        $id = $this->submitApplication($customer);

        DB::table('shop_applications')->where('id', $id)->update([
            'status' => 'rejected',
            'review_notes' => 'Please provide a clearer business address.',
        ]);

        $this->actingAs($customer)
            ->getJson('/api/shop-applications/mine')
            ->assertOk()
            ->assertJsonPath('applications.0.id', $id)
            ->assertJsonPath('applications.0.status', 'rejected')
            ->assertJsonPath('applications.0.review_notes', 'Please provide a clearer business address.');
    }

    public function test_customer_cannot_list_admin_applications(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->getJson('/api/admin/shop-applications')
            ->assertForbidden();
    }

    public function test_super_admin_can_list_all_applications(): void
    {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $applicant = User::factory()->create();

        $this->submitApplication($applicant);

        $this->actingAs($admin)
            ->getJson('/api/admin/shop-applications')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.business_name', 'SmartStock Test Shop');
    }

    public function test_invalid_review_decision_is_rejected(): void
    {
        $applicant = User::factory()->create();
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $id = $this->submitApplication($applicant);

        $this->actingAs($admin)
            ->postJson("/api/admin/shop-applications/{$id}/review", [
                'decision' => 'pending',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision']);

        $this->assertDatabaseHas('shop_applications', [
            'id' => $id,
            'status' => 'pending',
        ]);
    }
}