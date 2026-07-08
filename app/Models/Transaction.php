<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'transaction_number', 'transaction_type',
        'payment_method', 'subtotal', 'discount_amount', 'total', 'paid', 'change', 'status',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid' => 'decimal:2',
        'change' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function meta(): HasMany
    {
        return $this->hasMany(TransactionMeta::class);
    }

    // Helper ambil satu meta value by key, contoh: $transaction->getMeta('nomor_polisi')
    public function getMeta(string $key): ?string
    {
        return $this->meta->firstWhere('key', $key)?->value;
    }
}