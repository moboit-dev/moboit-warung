<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionReward extends Model
{
    protected $fillable = [
        'promotion_id',
        'product_id', // null = produk yang sama dengan syarat (kondisi) yang dipicu
        'quantity',
        'discount_percent',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'discount_percent' => 'decimal:2',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
