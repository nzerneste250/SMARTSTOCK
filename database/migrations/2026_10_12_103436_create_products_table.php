<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->foreignId('shop_id')
                ->constrained('shops')->restrictOnDelete();

            $table->foreignId('product_category_id')
                ->nullable()
                ->constrained('product_categories')
                ->restrictOnDelete();

            $table->string('name');
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->text('description')->nullable();

            $table->decimal('selling_price', 15, 2);
            $table->decimal('cost_price', 15, 2)->nullable();

            $table->string('unit', 30)->default('piece');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'sku']);
            $table->index(['shop_id', 'barcode']);
            $table->index(['shop_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};