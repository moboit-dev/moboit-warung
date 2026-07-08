<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant; // sesuaikan namespace trait ini dengan yang dipakai di model Product
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    use BelongsToTenant; // otomatis isi tenant_id saat create, sama seperti Product

    protected $fillable = [
        'tenant_id',
        'nama',
        'kontak',
        'alamat',
        'catatan',
    ];
}
