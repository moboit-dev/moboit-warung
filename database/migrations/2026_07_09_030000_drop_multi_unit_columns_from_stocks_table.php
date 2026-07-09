<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membalikkan migration 2026_07_04_000002_add_multi_unit_to_stocks_table:
     * produk sekarang selalu single-unit, jadi qty_besar/qty_kecil tidak lagi
     * dipakai. Sebelum drop, jumlahkan sisa qty_besar+qty_kecil kembali ke
     * 'quantity' supaya data stok tidak hilang.
     */
    public function up(): void
    {
        DB::table('stocks')->update([
            'quantity' => DB::raw('qty_besar + qty_kecil'),
        ]);

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['qty_besar', 'qty_kecil']);
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->integer('qty_besar')->default(0)->after('quantity');
            $table->integer('qty_kecil')->default(0)->after('qty_besar');
        });

        DB::table('stocks')->update([
            'qty_kecil' => DB::raw('quantity'),
        ]);
    }
};
