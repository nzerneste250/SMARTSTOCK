<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales', 'event_id')) {
            return;
        }

        if (DB::table('sales')->count() > 0) {
            throw new RuntimeException(
                'Sales table contains records. Review existing data before adding required columns.'
            );
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('event_id')->unique();

            $table->foreignId('shop_id')
                ->constrained('shops')
                ->restrictOnDelete();

            $table->foreignId('cashier_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('receipt_number', 60)->unique();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('total', 15, 2);

            $table->string('payment_method', 30);
            $table->string('status', 30)->default('completed');

            $table->timestamp('sold_at')->useCurrent();

            $table->index(['shop_id', 'sold_at']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        // Deliberately non-destructive.
        // Do not automatically remove sales transaction columns.
    }
};