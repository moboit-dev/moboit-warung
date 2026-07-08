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

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'sku',
        'cost_price', 'price', 'type', 'track_stock',
        'unit_besar', 'unit_kecil', 'conversion_qty', 'price_besar',
    ];

    protected $casts = [
        'cost_price' => 'float',
        'price' => 'float',
        'price_besar' => 'float',
        'track_stock' => 'boolean',
        'conversion_qty' => 'integer',
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

    /**
     * Produk ini punya 2 satuan dengan konversi (mis. Box vs Sachet)?
     * Kalau false, produk dianggap satuan tunggal seperti biasa —
     * seluruh logika auto-break tidak akan pernah berjalan untuknya.
     */
    public function hasMultiUnit(): bool
    {
        return ! empty($this->unit_besar)
            && ! empty($this->unit_kecil)
            && ! empty($this->conversion_qty)
            && $this->conversion_qty > 0;
    }
}
