<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'product_id', 'quantity', 'qty_besar', 'qty_kecil',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'qty_besar' => 'integer',
        'qty_kecil' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Total stok dikonversi semuanya ke satuan kecil — dipakai untuk
     * laporan/nilai stok, bukan untuk logika pengurangan (yang harus
     * lewat StockService supaya tercatat sebagai movement).
     */
    public function totalDalamSatuanKecil(): int
    {
        $product = $this->product;

        if (! $product || ! $product->hasMultiUnit()) {
            return $this->qty_kecil;
        }

        return ($this->qty_besar * $product->conversion_qty) + $this->qty_kecil;
    }
}
