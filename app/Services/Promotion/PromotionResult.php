<?php

namespace App\Services\Promotion;

/**
 * Hasil evaluasi promo untuk satu keranjang transaksi.
 * Dikonsumsi oleh layer transaksi untuk menghitung total akhir & mencatat
 * promotion_id mana saja yang terpakai (berguna untuk laporan/analitik promo).
 */
class PromotionResult
{
    /** @param AppliedItemDiscount[] $itemDiscounts */
    /** @param BonusItem[] $bonusItems */
    public function __construct(
        public readonly array $itemDiscounts,
        public readonly ?AppliedCartDiscount $cartDiscount,
        public readonly array $bonusItems,
        public readonly float $subtotal,
    ) {
    }

    public function totalItemDiscount(): float
    {
        return array_sum(array_map(fn (AppliedItemDiscount $d) => $d->amount, $this->itemDiscounts));
    }

    public function totalCartDiscount(): float
    {
        return $this->cartDiscount?->amount ?? 0.0;
    }

    public function grandTotal(): float
    {
        return round($this->subtotal - $this->totalItemDiscount() - $this->totalCartDiscount(), 2);
    }
}
