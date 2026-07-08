<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * qty_besar  = sisa box/dus yang masih utuh/tertutup.
     * qty_kecil  = sisa satuan eceran lepasan (termasuk hasil bongkar box).
     *
     * Kolom 'quantity' yang sudah ada TIDAK dihapus (biar tidak ada
     * breaking change untuk kode lain yang mungkin masih membacanya),
     * tapi logika baru tidak lagi memakainya — nilainya di-backfill
     * sekali ke qty_kecil di migration ini.
     */
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->integer('qty_besar')->default(0)->after('quantity');
            $table->integer('qty_kecil')->default(0)->after('qty_besar');
        });

        // Backfill: semua stok lama dianggap satuan kecil (belum ada
        // konsep box saat data ini dibuat).
        DB::table('stocks')->update([
            'qty_kecil' => DB::raw('quantity'),
        ]);
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['qty_besar', 'qty_kecil']);
        });
    }
};
