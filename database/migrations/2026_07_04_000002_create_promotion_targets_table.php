<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dipakai HANYA untuk promotions.type in (percentage, fixed)
        // dengan promotions.target_type in (product, category).
        // Bila promotions.target_type = cart, tabel ini tidak dipakai (diskon berlaku ke seluruh transaksi).
        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            $table->enum('target_type', ['product', 'category']);
            $table->unsignedBigInteger('target_id'); // id produk atau id kategori, tergantung target_type

            $table->timestamps();

            $table->index(['promotion_id']);
            $table->index(['target_type', 'target_id'], 'promotion_targets_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_targets');
    }
};
