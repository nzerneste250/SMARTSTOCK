<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();

            $table->foreignId('shop_id')
                ->constrained('shops')->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')->restrictOnDelete();

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('movement_type', 30);
            $table->decimal('quantity_change', 14, 3);
            $table->decimal('quantity_before', 14, 3);
            $table->decimal('quantity_after', 14, 3);

            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};