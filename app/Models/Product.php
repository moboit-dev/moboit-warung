<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    // Catatan: kolom multi-unit (unit_besar, unit_kecil, conversion_qty,
    // price_besar) sudah dihapus - produk sekarang selalu single-unit,
    // memakai kolom `unit` sebagai satuan tunggal.
    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'sku',
        'cost_price', 'price', 'type', 'track_stock', 'unit',
    ];

    protected $casts = [
        'cost_price' => 'float',
        'price' => 'float',
        'track_stock' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(Stock::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
