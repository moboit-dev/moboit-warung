<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();

            // Merujuk ke baris item pembelian asli, supaya bisa divalidasi
            // qty retur tidak melebihi qty yang dibeli (dikurangi retur sebelumnya).
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');

            $table->integer('qty');

            // Snapshot harga beli asal, dipakai untuk laporan nilai barang yang diretur.
            $table->decimal('harga_satuan', 15, 2);
            $table->decimal('subtotal', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
    }
};
