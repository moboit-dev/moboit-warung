<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahan untuk support 2 satuan + konversi (mis. Box vs Sachet).
     *
     * - unit_besar / unit_kecil: nullable. Kalau produk cuma 1 satuan,
     *   biarkan kedua kolom ini null — logika penjualan akan otomatis
     *   memperlakukan produk itu seperti biasa (tidak ada konversi).
     * - conversion_qty: berapa unit_kecil dalam 1 unit_besar (mis. 24).
     * - price_besar: harga jual per unit_besar (harga grosir). Kolom
     *   'price' yang sudah ada TETAP dipakai sebagai harga per unit_kecil
     *   (eceran) — tidak diubah maknanya, supaya tidak ada breaking change
     *   untuk produk yang belum pakai multi-satuan.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit_besar')->nullable()->after('type');
            $table->string('unit_kecil')->nullable()->after('unit_besar');
            $table->unsignedInteger('conversion_qty')->nullable()->after('unit_kecil');
            $table->decimal('price_besar', 15, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['unit_besar', 'unit_kecil', 'conversion_qty', 'price_besar']);
        });
    }
};
