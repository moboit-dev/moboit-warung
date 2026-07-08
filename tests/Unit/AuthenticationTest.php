<?php

/**
 * Feature test untuk AuthController.
 *
 * CATATAN PENTING SEBELUM DIJALANKAN:
 * 1. Taruh file ini di: tests/Feature/AuthenticationTest.php
 * 2. Endpoint di bawah ini ASUMSI nama route standar:
 *      POST   /api/register
 *      POST   /api/login
 *      POST   /api/logout   (auth:sanctum)
 *      GET    /api/me       (auth:sanctum)
 *      POST   /api/users    (auth:sanctum, createUser)
 *    Kalau di routes/api.php kamu pakai path/prefix berbeda
 *    (mis. /api/v1/... atau /api/auth/login), sesuaikan konstanta
 *    di bawah ini saja — tidak perlu ubah body test.
 * 3. Pastikan database testing sudah dikonfigurasi (biasanya sqlite
 *    in-memory) di phpunit.xml, karena test ini pakai RefreshDatabase.
 */

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

const REGISTER_URL = '/api/register';
const LOGIN_URL = '/api/login';
const LOGOUT_URL = '/api/logout';
const ME_URL = '/api/me';
const CREATE_USER_URL = '/api/users';

/**
 * Helper: bikin tenant + user langsung ke DB tanpa lewat endpoint
 * register (dipakai untuk skenario yang butuh user "sudah ada").
 * Pakai withoutGlobalScope('tenant') saat query/manipulasi cross-tenant
 * di dalam test, karena kita berada di luar konteks HTTP request biasa.
 */
function buatTenantDenganOwner(array $userOverrides = []): array
{
    $tenant = Tenant::create([
        'name' => 'Warung Test',
        'slug' => 'warung-test-' . uniqid(),
        'status' => 'trial',
        'trial_ends_at' => now()->addDays(14),
    ]);

    $owner = User::create(array_merge([
        'tenant_id' => $tenant->id,
        'name' => 'Owner Test',
        'email' => 'owner@test.com',
        'phone' => '081200000000',
        'password' => Hash::make('password123'),
        'role' => 'owner',
        'is_active' => true,
    ], $userOverrides));

    return [$tenant, $owner];
}

// ============================================================
// REGISTER
// ============================================================

it('berhasil registrasi tenant baru sekaligus owner-nya', function () {
    $response = $this->postJson(REGISTER_URL, [
        'tenant_name' => 'Warung Bu Siti',
        'tenant_address' => 'Jl. Mawar No. 1',
        'owner_name' => 'Siti',
        'email' => 'siti@example.com',
        'phone' => '081234567890',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'tenant', 'user', 'token']);

    expect(User::withoutGlobalScope('tenant')->where('email', 'siti@example.com')->exists())
        ->toBeTrue();

    expect(Tenant::where('name', 'Warung Bu Siti')->exists())->toBeTrue();

    $owner = User::withoutGlobalScope('tenant')->where('email', 'siti@example.com')->first();
    expect($owner->role)->toBe('owner');
    expect($owner->tenant_id)->toBe(Tenant::where('name', 'Warung Bu Siti')->first()->id);
});

it('menolak registrasi dengan email yang sudah dipakai tenant lain', function () {
    buatTenantDenganOwner(['email' => 'dipakai@example.com', 'phone' => '081111111111']);

    $response = $this->postJson(REGISTER_URL, [
        'tenant_name' => 'Warung Baru',
        'owner_name' => 'Budi',
        'email' => 'dipakai@example.com', // sama dengan tenant lain
        'phone' => '082222222222',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    // Sesuai keputusan desain: email unik GLOBAL lintas tenant,
    // jadi ini WAJIB ditolak, bukan dibolehkan.
    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

it('menolak registrasi tanpa email maupun phone', function () {
    $response = $this->postJson(REGISTER_URL, [
        'tenant_name' => 'Warung Tanpa Kontak',
        'owner_name' => 'Budi',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

// ============================================================
// LOGIN
// ============================================================

it('berhasil login pakai email dan password yang benar', function () {
    buatTenantDenganOwner(['email' => 'login@example.com', 'phone' => '081300000001']);

    $response = $this->postJson(LOGIN_URL, [
        'login' => 'login@example.com',
        'password' => 'password123',
    ]);

    // Ini REGRESSION TEST untuk bug tenant-scope yang sudah diperbaiki:
    // sebelum fix, query User::where(...) di dalam login() selalu
    // mengembalikan 0 baris karena global scope tenant menyuntikkan
    // whereRaw('1=0') saat belum ada auth context. Kalau bug ini
    // muncul lagi (mis. karena refactor tanpa sengaja menghapus
    // withoutGlobalScope), assertion di bawah ini akan gagal dengan 401.
    $response->assertOk()
        ->assertJsonStructure(['user', 'tenant', 'token']);
});

it('berhasil login pakai nomor HP dan password yang benar', function () {
    buatTenantDenganOwner(['email' => 'lewatphone@example.com', 'phone' => '081300000002']);

    $response = $this->postJson(LOGIN_URL, [
        'login' => '081300000002',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['user', 'tenant', 'token']);
});

it('menolak login dengan password salah', function () {
    buatTenantDenganOwner(['email' => 'salahpass@example.com', 'phone' => '081300000003']);

    $response = $this->postJson(LOGIN_URL, [
        'login' => 'salahpass@example.com',
        'password' => 'password-yang-salah',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Email/nomor HP atau password salah.']);
});

it('menolak login dengan email yang tidak terdaftar', function () {
    $response = $this->postJson(LOGIN_URL, [
        'login' => 'tidak-ada@example.com',
        'password' => 'apasaja123',
    ]);

    $response->assertUnauthorized();
});

it('menolak login untuk user yang sudah dinonaktifkan', function () {
    buatTenantDenganOwner([
        'email' => 'nonaktif@example.com',
        'phone' => '081300000004',
        'is_active' => false,
    ]);

    $response = $this->postJson(LOGIN_URL, [
        'login' => 'nonaktif@example.com',
        'password' => 'password123',
    ]);

    $response->assertForbidden()
        ->assertJson(['message' => 'Akun kamu sudah dinonaktifkan. Hubungi owner/admin toko.']);
});

it('tetap bisa login walau tenant sudah expired', function () {
    [$tenant] = buatTenantDenganOwner([
        'email' => 'expired@example.com',
        'phone' => '081300000005',
    ]);

    $tenant->update(['status' => 'expired', 'trial_ends_at' => now()->subDay()]);

    $response = $this->postJson(LOGIN_URL, [
        'login' => 'expired@example.com',
        'password' => 'password123',
    ]);

    $response->assertOk();
});

// ============================================================
// LOGOUT
// ============================================================

it('berhasil logout dan token menjadi tidak valid', function () {
    [, $owner] = buatTenantDenganOwner(['email' => 'logout@example.com', 'phone' => '081300000006']);
    $token = $owner->createToken('auth_token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(LOGOUT_URL);

    $response->assertOk();

    // Token yang sama tidak boleh bisa dipakai lagi setelah logout.
    //
    // Perlu forgetGuards() di sini karena dua request HTTP di test ini
    // (logout, lalu getJson ME_URL) berjalan dalam instance aplikasi
    // yang sama. Guard Sanctum mem-cache user hasil resolusi dari
    // request pertama, jadi tanpa reset ini, request kedua akan
    // "mengingat" user yang sudah logout alih-alih query ulang ke
    // database (di mana tokennya sudah terhapus). Ini murni artefak
    // testing, tidak terjadi pada request HTTP asli di production.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(ME_URL)
        ->assertUnauthorized();
});

// ============================================================
// ME
// ============================================================

it('mengembalikan data user dan tenant yang sedang login', function () {
    [$tenant, $owner] = buatTenantDenganOwner(['email' => 'me@example.com', 'phone' => '081300000007']);
    $token = $owner->createToken('auth_token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson(ME_URL);

    $response->assertOk()
        ->assertJsonPath('user.email', 'me@example.com')
        ->assertJsonPath('tenant.id', $tenant->id);
});

// ============================================================
// CREATE USER (owner/admin bikin kasir baru)
// ============================================================

it('owner berhasil membuat user kasir baru di tenant yang sama', function () {
    [$tenant, $owner] = buatTenantDenganOwner(['email' => 'ownercu@example.com', 'phone' => '081300000008']);
    $token = $owner->createToken('auth_token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(CREATE_USER_URL, [
            'name' => 'Kasir Satu',
            'email' => 'kasir1@example.com',
            'phone' => '081300000009',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'kasir',
        ]);

    $response->assertCreated();

    $kasir = User::withoutGlobalScope('tenant')->where('email', 'kasir1@example.com')->first();
    expect($kasir)->not->toBeNull();
    expect($kasir->tenant_id)->toBe($tenant->id);
    expect($kasir->role)->toBe('kasir');
});

it('kasir tidak boleh membuat user baru (bukan owner/admin)', function () {
    [$tenant, $ownerLain] = buatTenantDenganOwner(['email' => 'tenantkasir@example.com', 'phone' => '081300000011']);
    $kasir = User::create([
        'tenant_id' => $tenant->id,
        'name' => 'Kasir Biasa',
        'email' => 'kasirbiasa@example.com',
        'phone' => '081300000012',
        'password' => Hash::make('password123'),
        'role' => 'kasir',
        'is_active' => true,
    ]);
    $token = $kasir->createToken('auth_token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(CREATE_USER_URL, [
            'name' => 'Kasir Dua',
            'email' => 'kasir2@example.com',
            'phone' => '081300000013',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'kasir',
        ]);

    // Otorisasi ini seharusnya dicek di dalam CreateUserRequest::authorize().
    // Kalau assertion ini gagal, berarti CreateUserRequest belum
    // benar-benar membatasi berdasarkan canManageUsers().
    $response->assertForbidden();
});
