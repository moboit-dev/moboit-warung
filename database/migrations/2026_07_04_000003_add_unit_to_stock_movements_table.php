<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * unit menjelaskan apakah baris movement ini terjadi di satuan besar
     * (mis. box) atau satuan kecil (mis. sachet). Default 'kecil' untuk
     * baris lama, karena sebelum fitur ini semua tracking memang di
     * satuan tunggal yang setara satuan kecil.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('unit')->default('kecil')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
