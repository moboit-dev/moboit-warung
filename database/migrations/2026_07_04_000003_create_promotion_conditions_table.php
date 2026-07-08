<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dipakai HANYA untuk promotions.type = bogo.
        // Syarat: transaksi harus memuat product_id sebanyak minimal min_quantity.
        //
        // ASUMSI: satu promosi bogo bisa punya lebih dari satu baris condition,
        // dan SEMUA condition harus terpenuhi (AND), bukan salah satu (OR).
        // Contoh: "Beli sabun A 2 + sabun B 1" -> 2 baris condition untuk 1 promotion_id yang sama.
        Schema::create('promotion_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('min_quantity')->default(1);

            $table->timestamps();

            $table->index(['promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_conditions');
    }
};
