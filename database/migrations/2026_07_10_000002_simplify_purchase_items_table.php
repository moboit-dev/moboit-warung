<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * satuan_dibeli (besar/kecil) & conversion_qty_snapshot adalah sisa
 * fitur multi-unit yang sudah tidak dipakai. Produk sekarang selalu
 * single-unit, jadi qty item pembelian tidak butuh info satuan lagi -
 * satuan yang ditampilkan cukup ambil dari product itu sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            foreach (['satuan_dibeli', 'conversion_qty_snapshot'] as $column) {
                if (Schema::hasColumn('purchase_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->enum('satuan_dibeli', ['besar', 'kecil'])->nullable();
            $table->integer('conversion_qty_snapshot')->nullable();
        });
    }
};
