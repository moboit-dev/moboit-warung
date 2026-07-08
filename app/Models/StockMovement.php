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

    /**
     * Movement khusus saat 1 unit_besar "dibongkar" jadi beberapa
     * unit_kecil karena stok unit_kecil sudah habis. Dicatat sebagai
     * 2 baris terpisah (lihat StockService::sellKecilDenganAutoBreak):
     * satu baris UNIT_BESAR (quantity negatif, box berkurang) dan satu
     * baris UNIT_KECIL (quantity positif, sachet hasil bongkar).
     */
    public const TYPE_BREAK_UNIT = 'break_unit';

    public const UNIT_BESAR = 'besar';
    public const UNIT_KECIL = 'kecil';

    protected $fillable = [
        'tenant_id', 'product_id', 'type', 'unit', 'quantity',
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
