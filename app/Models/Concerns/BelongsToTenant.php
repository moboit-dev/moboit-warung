<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        // === GLOBAL SCOPE: auto-filter semua query berdasarkan tenant_id ===
        // Prinsip: default HARUS "tidak menampilkan apa-apa" kalau tidak ada
        // tenant context yang jelas — bukan diam-diam menampilkan semua data.
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = static::resolveCurrentTenantId();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
                return;
            }

            // Tidak ada tenant context sama sekali.
            // Jangan biarkan query lolos tanpa filter — matikan hasilnya.
            // Kalau memang butuh akses lintas-tenant (mis. command/cron),
            // panggil ::withoutGlobalScope('tenant') secara EKSPLISIT
            // dan set tenant context manual di sana.
            $builder->whereRaw('1 = 0');
        });

        // === AUTO-FILL tenant_id saat bikin record baru ===
        static::creating(function ($model) {
            if (! empty($model->tenant_id)) {
                // tenant_id sudah di-set manual sebelumnya (mis. dari job/seeder
                // yang sudah tahu tenant context-nya). Biarkan, jangan ditimpa.
                return;
            }

            $tenantId = static::resolveCurrentTenantId();

            if ($tenantId === null) {
                // Jangan biarkan record "yatim" tanpa tenant lolos ke database.
                throw new \RuntimeException(
                    class_basename($model) . ' gagal dibuat: tidak ada tenant context yang aktif. '
                    . 'Set tenant_id secara eksplisit jika dibuat dari luar request HTTP '
                    . '(mis. job, command, seeder, tinker).'
                );
            }

            $model->tenant_id = $tenantId;
        });

        // === PROTEKSI: cegah tenant_id diubah setelah dibuat ===
        // Mencegah kasus data "dipindah tenant" secara tidak sengaja
        // (mis. mass-assignment dari request yang ceroboh).
        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new \RuntimeException(
                    class_basename($model) . ': tenant_id tidak boleh diubah setelah record dibuat.'
                );
            }
        });
    }

    /**
     * Ambil tenant_id dari context yang sedang aktif.
     *
     * Urutan prioritas:
     * 1. Tenant context yang di-set manual (untuk job/command/console) via
     *    static::runForTenant() atau app()->instance('current_tenant_id', ...).
     * 2. User yang sedang login (request HTTP biasa).
     *
     * Return null kalau memang tidak ada context sama sekali —
     * caller (global scope / creating hook) yang menentukan mau
     * di-block atau dilempar exception.
     */
    protected static function resolveCurrentTenantId(): ?int
    {
        // 1. Context manual (dipakai job/command/queue yang sudah tahu tenant-nya)
        if (app()->bound('current_tenant_id')) {
            return app('current_tenant_id');
        }

        // 2. User yang sedang login lewat request HTTP
        if (auth()->check() && auth()->user()->tenant_id) {
            return auth()->user()->tenant_id;
        }

        return null;
    }

    /**
     * Jalankan sebuah closure dalam konteks tenant tertentu.
     * Wajib dipakai di job/command/cron yang perlu operasi atas nama
     * tenant tertentu tanpa auth() (mis. proses background per-tenant).
     *
     * Contoh pemakaian:
     *   Product::runForTenant($tenantId, function () {
     *       Product::create([...]); // tenant_id otomatis terisi $tenantId
     *   });
     */
    public static function runForTenant(int $tenantId, \Closure $callback): mixed
    {
        app()->instance('current_tenant_id', $tenantId);

        try {
            return $callback();
        } finally {
            app()->forgetInstance('current_tenant_id');
        }
    }

    /**
     * Query lintas-tenant secara EKSPLISIT (mis. untuk admin panel super-admin,
     * laporan gabungan semua tenant, dsb). Harus dipanggil sadar, bukan
     * kebetulan lolos karena tidak ada auth context.
     *
     * Contoh: Product::allTenants()->get();
     */
    public static function allTenants(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}