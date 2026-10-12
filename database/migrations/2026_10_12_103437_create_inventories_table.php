<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shop_id')
                ->constrained('shops')->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')->restrictOnDelete();

            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('low_stock_threshold', 14, 3)->default(0);
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['shop_id', 'quantity_on_hand']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};