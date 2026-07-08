<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CreateUserRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Registrasi tenant baru sekaligus user pertama (owner).
     *
     * PENTING: owner_name, email, phone, password ini adalah data
     * USER (owner), BUKAN kolom di tabel tenants. Tabel tenants hanya
     * punya name/slug/phone/email/address/status/trial_ends_at milik
     * warung itu sendiri — lihat migration create_tenants_table.
     *
     * Email/phone unik secara GLOBAL lintas semua tenant (lihat
     * RegisterTenantRequest::rules() -> Rule::unique tanpa scoping
     * tenant). Ini keputusan desain yang disengaja: satu orang yang
     * punya toko berbeda WAJIB registrasi ulang dengan email/phone
     * yang berbeda untuk tiap toko. Satu email tidak bisa dipakai
     * untuk lebih dari satu tenant.
     */
    public function register(RegisterTenantRequest $request): JsonResponse
    {
        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::create([
                'name' => $request->tenant_name,
                'slug' => $this->generateUniqueSlug($request->tenant_name),
                'address' => $request->tenant_address,
                'status' => 'trial',
                'trial_ends_at' => now()->addDays(14),
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->owner_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
                'role' => 'owner',
                'is_active' => true,
            ]);

            $tenant->setRelation('owner', $owner);

            return $tenant;
        });

        $owner = $tenant->getRelation('owner');
        $token = $owner->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil.',
            'tenant' => $tenant,
            'user' => $owner,
            'token' => $token,
        ], 201);
    }

    /**
     * Login pakai email ATAU phone (ditentukan otomatis dari format input).
     *
     * PENTING: pakai withoutGlobalScope('tenant') di sini secara SENGAJA.
     * Global scope dari BelongsToTenant butuh tenant context yang biasanya
     * datang dari auth()->user() yang sedang login — tapi saat login,
     * belum ada siapa pun yang login. Tanpa bypass ini, scope akan
     * menyuntikkan whereRaw('1=0') dan query User TIDAK AKAN PERNAH
     * menemukan siapa pun, membuat login selalu gagal walau kredensial
     * benar.
     *
     * Ini aman: kita memang butuh mencari user lintas-tenant di titik ini
     * (belum tahu tenant-nya sebelum user ketemu). Setelah user ditemukan
     * dan password cocok, token yang di-issue tetap terikat ke tenant_id
     * milik user tersebut, jadi semua request setelah login tetap
     * ter-scope dengan benar lewat auth()->user()->tenant_id.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $user = User::withoutGlobalScope('tenant')
            ->where($field, $request->login)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Email/nomor HP atau password salah.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun kamu sudah dinonaktifkan. Hubungi owner/admin toko.',
            ], 403);
        }

        // Tenant expired tetap boleh login (biar bisa lihat data/upgrade),
        // tapi endpoint transaksi lain yang mengecek status ini secara terpisah.

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil logout.',
        ]);
    }

    /**
     * Owner/admin membuat user baru (admin/kasir) di dalam tenant yang sama.
     * Otorisasi (canManageUsers) sudah dicek di dalam CreateUserRequest.
     *
     * Tidak perlu withoutGlobalScope di sini — $request->user() sudah
     * ter-auth, dan tenant_id di-set eksplisit dari user yang login,
     * bukan bergantung pada global scope untuk create.
     */
    public function createUser(CreateUserRequest $request): JsonResponse
    {
        $user = User::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => $user,
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'tenant' => $request->user()->tenant,
        ]);
    }

    /**
     * Generate slug unik dari nama tenant. Kolom slug di migration
     * bersifat unique() dan tidak nullable, jadi WAJIB di-generate di sini
     * — request registrasi tidak mengirim slug sama sekali.
     */
    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
