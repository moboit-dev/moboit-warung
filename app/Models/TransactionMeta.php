<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionMeta extends Model
{
    protected $fillable = ['transaction_id', 'key', 'value'];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}