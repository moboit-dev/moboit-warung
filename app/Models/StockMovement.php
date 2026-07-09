<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant;

    public const TYPE_IN = 'in';
    public const TYPE_OUT = 'out';
    public const TYPE_ADJUSTMENT = 'adjustment';

    // Catatan: satuan besar/kecil (UNIT_BESAR, UNIT_KECIL) dan
    // TYPE_BREAK_UNIT sudah dihapus - produk sekarang selalu single-unit,
    // jadi tidak ada lagi konsep "bongkar" box -> sachet.
    protected $fillable = [
        'tenant_id', 'product_id', 'type', 'quantity',
        'note', 'reference_id', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
