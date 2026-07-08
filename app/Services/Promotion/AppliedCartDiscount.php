<?php

namespace App\Services\Promotion;

class AppliedCartDiscount
{
    public function __construct(
        public readonly int $promotionId,
        public readonly string $promotionName,
        public readonly float $amount,
    ) {
    }
}
