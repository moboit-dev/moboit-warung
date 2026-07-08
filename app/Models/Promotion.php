<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'target_type',
        'value',
        'start_date',
        'end_date',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(PromotionCondition::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(PromotionReward::class);
    }

    public function isPercentage(): bool
    {
        return $this->type === 'percentage';
    }

    public function isFixed(): bool
    {
        return $this->type === 'fixed';
    }

    public function isBogo(): bool
    {
        return $this->type === 'bogo';
    }

    /** Promo level "item": kena ke produk/kategori tertentu. */
    public function isItemLevel(): bool
    {
        return in_array($this->type, ['percentage', 'fixed'], true)
            && in_array($this->target_type, ['product', 'category'], true);
    }

    /** Promo level "cart": kena ke total transaksi. */
    public function isCartLevel(): bool
    {
        return in_array($this->type, ['percentage', 'fixed'], true)
            && $this->target_type === 'cart';
    }

    /** Aktif & berada dalam rentang tanggal berlaku pada waktu $at. */
    public function scopeActiveAt(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query->where('is_active', true)
            ->where('start_date', '<=', $at)
            ->where('end_date', '>=', $at);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
