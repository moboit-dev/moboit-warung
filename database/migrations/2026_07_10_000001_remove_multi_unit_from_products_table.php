<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fitur satuan besar/kecil (multi-unit) dihapus - produk sekarang selalu
 * single-unit. Migration ini membersihkan kolom-kolom yang sudah tidak
 * dipakai lagi di tabel products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = ['unit_besar', 'unit_kecil', 'conversion_qty', 'price_besar'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit_besar')->nullable();
            $table->string('unit_kecil')->nullable();
            $table->integer('conversion_qty')->nullable();
            $table->decimal('price_besar', 15, 2)->nullable();
        });
    }
};
