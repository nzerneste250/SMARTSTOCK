<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShopApprovalService
{
    public function review(
        int $applicationId,
        User $reviewer,
        string $decision,
        ?string $notes
    ): array {
        if ($reviewer->platform_role !== 'super_admin') {
            abort(403);
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            abort(422, 'Invalid decision.');
        }

        return DB::transaction(function () use (
            $applicationId,
            $reviewer,
            $decision,
            $notes
        ) {
            $application = DB::table('shop_applications')
                ->where('id', $applicationId)
                ->lockForUpdate()
                ->first();

            if (!$application) {
                abort(404, 'Application not found.');
            }

            if ($application->status !== 'pending') {
                throw new ConflictHttpException(
                    'This application has already been reviewed.'
                );
            }

            $shopId = null;

            if ($decision === 'approved') {
                $shopId = DB::table('shops')->insertGetId([
                    'public_id' => (string) Str::uuid(),
                    'name' => $application->business_name,
                    'code' => 'SS-' . strtoupper(Str::random(16)),
                    'business_type' => $application->business_type,
                    'phone' => $application->phone,
                    'province' => $application->province,
                    'district' => $application->district,
                    'sector' => $application->sector,
                    'address' => $application->address,
                    'status' => 'active',
                    'approved_by' => $reviewer->id,
                    'approved_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('shop_memberships')->insert([
                    'shop_id' => $shopId,
                    'user_id' => $application->applicant_user_id,
                    'role' => 'shop_admin',
                    'status' => 'active',
                    'joined_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('shop_applications')
                ->where('id', $applicationId)
                ->update([
                    'status' => $decision,
                    'shop_id' => $shopId,
                    'reviewed_by' => $reviewer->id,
                    'review_notes' => $notes,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('audit_logs')->insert([
                'event_id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'user_id' => $reviewer->id,
                'action' => 'shop_application.' . $decision,
                'subject_type' => 'shop_application',
                'subject_id' => (string) $applicationId,
                'new_values' => json_encode([
                    'decision' => $decision,
                    'shop_id' => $shopId,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'application_id' => $applicationId,
                'status' => $decision,
                'shop_id' => $shopId,
            ];
        }, 3);
    }
}