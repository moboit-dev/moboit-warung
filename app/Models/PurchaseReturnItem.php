<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnItem extends Model
{
    use HasFactory;

    // Tidak pakai BelongsToTenant - scope lewat relasi ke PurchaseReturn.
    protected $fillable = [
        'purchase_return_id', 'purchase_item_id', 'product_id',
        'qty', 'harga_satuan', 'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
        'harga_satuan' => 'float',
        'subtotal' => 'float',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
