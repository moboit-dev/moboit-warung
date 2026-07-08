<?php

namespace App\Services\Promotion;

/**
 * Representasi satu baris item di keranjang, sebelum promo dievaluasi.
 * Ini bukan Eloquent model — sengaja dibuat value object ringan supaya
 * PromotionService tidak terikat ke struktur tabel transaction_items.
 */
class CartItem
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $categoryId,
        public readonly float $price,   // harga satuan, sebelum diskon
        public readonly int $quantity,
    ) {
    }

    public function lineTotal(): float
    {
        return $this->price * $this->quantity;
    }
}
