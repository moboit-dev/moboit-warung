<?php

namespace App\Services\Promotion;

class BonusItem
{
    public function __construct(
        public readonly int $productId,
        public readonly int $quantity,
        public readonly float $discountPercent, // 100 = gratis penuh
        public readonly int $promotionId,
        public readonly string $promotionName,
    ) {
    }
}
