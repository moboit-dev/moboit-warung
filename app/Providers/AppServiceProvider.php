<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pakai PersonalAccessToken custom yang bypass global scope tenant
        // saat resolve tokenable. Tanpa ini, middleware auth:sanctum akan
        // selalu gagal (401) untuk SEMUA token yang valid, karena global
        // scope 'tenant' di User (lihat App\Models\Concerns\BelongsToTenant)
        // ikut memblokir query internal Sanctum saat mencari pemilik token
        // — di titik itu belum ada tenant context sama sekali.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
