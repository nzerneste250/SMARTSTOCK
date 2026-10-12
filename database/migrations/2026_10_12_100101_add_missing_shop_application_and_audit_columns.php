<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shop_applications', 'public_id')) {
            Schema::table('shop_applications', function (Blueprint $table) {
                $table->uuid('public_id')->unique();
                $table->foreignId('applicant_user_id');
                $table->foreignId('shop_id')->nullable();
                $table->string('business_name');
                $table->string('business_type')->nullable();
                $table->string('phone', 30);
                $table->string('province')->nullable();
                $table->string('district')->nullable();
                $table->string('sector')->nullable();
                $table->string('address')->nullable();
                $table->string('status', 20)->default('pending');
                $table->text('review_notes')->nullable();
                $table->foreignId('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();

                $table->index(['status', 'created_at']);
            });
        }

        if (!Schema::hasColumn('audit_logs', 'event_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->uuid('event_id')->unique();
                $table->foreignId('shop_id')->nullable();
                $table->foreignId('user_id')->nullable();
                $table->string('action', 100);
                $table->string('subject_type')->nullable();
                $table->string('subject_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->ipAddress('ip_address')->nullable();
                $table->string('user_agent', 512)->nullable();

                $table->index(['shop_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
                $table->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        // No destructive rollback.
        // Schema changes require a separate reviewed migration.
    }
};