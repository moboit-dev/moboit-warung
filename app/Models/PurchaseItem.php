<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    use HasFactory;

    // Sengaja TIDAK pakai BelongsToTenant - tabel ini tidak punya kolom
    // tenant_id, tenant di-scope lewat relasi ke Purchase.
    protected $fillable = [
        'purchase_id', 'product_id', 'satuan_dibeli', 'qty',
        'conversion_qty_snapshot', 'harga_satuan', 'subtotal',
    ];

    protected $casts = [
        'harga_satuan' => 'float',
        'subtotal' => 'float',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
