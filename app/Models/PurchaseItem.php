<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    use HasFactory;

    // Sengaja TIDAK pakai BelongsToTenant - tabel ini tidak punya kolom
    // tenant_id, tenant di-scope lewat relasi ke Purchase.
    protected $fillable = [
        'purchase_id', 'product_id', 'qty', 'harga_satuan', 'subtotal',
    ];

    protected $casts = [
        'qty' => 'integer',
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

    public function returnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /**
     * Total qty yang sudah diretur dari item ini (dari semua purchase_returns).
     * Dipakai untuk validasi supaya retur baru tidak melebihi sisa yang belum diretur.
     */
    public function qtyDiretur(): int
    {
        return (int) $this->returnItems()->sum('qty');
    }

    public function sisaBisaDiretur(): int
    {
        return max(0, $this->qty - $this->qtyDiretur());
    }
}
