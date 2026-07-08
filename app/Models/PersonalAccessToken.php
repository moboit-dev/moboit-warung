<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Override relasi tokenable dari Sanctum.
     *
     * PENTING: model tokenable (mis. User) punya global scope 'tenant'
     * dari trait BelongsToTenant. Saat middleware auth:sanctum mencoba
     * resolve user pemilik token, BELUM ADA siapa pun yang login —
     * jadi resolveCurrentTenantId() di global scope akan return null,
     * yang membuat scope itu memblokir query (whereRaw('1=0')).
     *
     * Akibatnya: Sanctum tidak akan pernah berhasil menemukan user
     * pemilik token manapun, dan SEMUA request yang butuh auth:sanctum
     * (logout, me, users, dst) akan selalu gagal dengan 401 — walau
     * tokennya valid.
     *
     * Fix: bypass semua global scope khusus di titik resolusi token ini.
     * Ini aman, karena tujuannya cuma "siapa pemilik token ini", bukan
     * query data bisnis biasa yang memang wajib di-scope per tenant.
     */
    public function tokenable(): MorphTo
    {
        return parent::tokenable()->withoutGlobalScopes();
    }
}
