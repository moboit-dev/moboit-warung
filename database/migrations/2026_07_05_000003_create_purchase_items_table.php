<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');

            // Nilai 'besar' / 'kecil' sengaja sama dengan StockMovement::UNIT_BESAR / UNIT_KECIL,
            // supaya langsung dipakai apa adanya saat approve() tanpa perlu mapping.
            $table->enum('satuan_dibeli', ['besar', 'kecil']);
            $table->integer('qty'); // qty sesuai satuan_dibeli, TIDAK dikonversi (stok box & sachet terpisah)

            // Snapshot conversion_qty produk saat transaksi ini dibuat - HANYA untuk keperluan
            // tampilan/laporan ("setara berapa sachet"), TIDAK dipakai untuk update stok.
            $table->integer('conversion_qty_snapshot')->nullable();

            $table->decimal('harga_satuan', 15, 2); // harga per satuan_dibeli
            $table->decimal('subtotal', 15, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
