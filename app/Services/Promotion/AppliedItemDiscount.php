<?php

namespace App\Services\Promotion;

class AppliedItemDiscount
{
    public function __construct(
        public readonly int $cartItemIndex, // index item di array cart yang dikirim ke service
        public readonly int $promotionId,
        public readonly string $promotionName,
        public readonly float $amount, // total potongan untuk baris item ini (sudah dikali quantity)
    ) {
    }
}
