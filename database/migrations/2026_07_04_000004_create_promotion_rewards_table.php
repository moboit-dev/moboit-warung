<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dipakai HANYA untuk promotions.type = bogo.
        // product_id null artinya "produk yang sama dengan yang dibeli" (dipakai untuk kasus
        // Buy 1 Get 1 di mana hadiahnya adalah produk yang sama dengan syaratnya).
        //
        // ASUMSI: sebuah promotion bogo bisa punya >1 baris reward (mis. beli A, dapat B + C),
        // dan seluruh reward diberikan bersamaan saat conditions terpenuhi.
        Schema::create('promotion_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->unsignedInteger('quantity')->default(1);

            // 100 = gratis penuh, <100 = diskon sebagian (mis. 50 = bayar setengah harga)
            $table->decimal('discount_percent', 5, 2)->default(100);

            $table->timestamps();

            $table->index(['promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rewards');
    }
};
