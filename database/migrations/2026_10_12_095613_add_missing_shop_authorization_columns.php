<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('shops', 'public_id')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->uuid('public_id')->unique();
                $table->string('name');
                $table->string('code')->unique();
                $table->string('business_type')->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email')->nullable();
                $table->string('province')->nullable();
                $table->string('district')->nullable();
                $table->string('sector')->nullable();
                $table->string('address')->nullable();
                $table->string('logo_path')->nullable();
                $table->string('currency', 3)->default('RWF');
                $table->string('status', 20)->default('pending');
                $table->foreignId('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->index(['status', 'district']);
            });
        }

        if (!Schema::hasColumn('shop_memberships', 'shop_id')) {
            Schema::table('shop_memberships', function (Blueprint $table) {
                $table->foreignId('shop_id');
                $table->foreignId('user_id');
                $table->string('role', 30);
                $table->string('status', 20)->default('active');
                $table->foreignId('invited_by')->nullable();
                $table->timestamp('joined_at')->nullable();

                $table->unique(['shop_id', 'user_id']);
                $table->index(['shop_id', 'role', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty to avoid accidental removal
        // of shop authorization data during rollback.
    }
};