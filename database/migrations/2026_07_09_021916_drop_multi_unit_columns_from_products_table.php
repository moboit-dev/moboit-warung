<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membalikkan migration 2026_07_04_000001_add_multi_unit_to_products_table:
     * produk sekarang selalu single-unit, jadi unit_besar, conversion_qty,
     * dan price_besar tidak lagi dipakai. unit_kecil TETAP dipertahankan
     * (di-rename jadi 'unit') karena itu satuan tunggal yang masih dipakai.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['unit_besar', 'conversion_qty', 'price_besar']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('unit_kecil', 'unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('unit', 'unit_kecil');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('unit_besar')->nullable()->after('type');
            $table->unsignedInteger('conversion_qty')->nullable()->after('unit_kecil');
            $table->decimal('price_besar', 15, 2)->nullable()->after('price');
        });
    }
};
