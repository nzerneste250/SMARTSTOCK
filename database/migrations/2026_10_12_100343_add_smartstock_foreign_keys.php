<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->foreign('approved_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('shop_memberships', function (Blueprint $table) {
            $table->foreign('shop_id')
                ->references('id')->on('shops')
                ->restrictOnDelete();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->foreign('invited_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('shop_applications', function (Blueprint $table) {
            $table->foreign('applicant_user_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->foreign('shop_id')
                ->references('id')->on('shops')
                ->nullOnDelete();

            $table->foreign('reviewed_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreign('shop_id')
                ->references('id')->on('shops')
                ->nullOnDelete();

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('shop_applications', function (Blueprint $table) {
            $table->dropForeign(['applicant_user_id']);
            $table->dropForeign(['shop_id']);
            $table->dropForeign(['reviewed_by']);
        });

        Schema::table('shop_memberships', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['invited_by']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
        });
    }
};