<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * GET /api/users
     * List user milik tenant yang login (otomatis ter-filter lewat
     * BelongsToTenant, sama seperti Product/Category).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManager($request);

        $users = User::query()->orderBy('name')->get();

        return response()->json(['data' => $users]);
    }

    /**
     * PUT /api/users/{user}
     * Dipakai untuk toggle aktif/nonaktif (Switch di UserListScreen) dan
     * bisa juga dipakai untuk ubah name/role kalau perlu nanti.
     *
     * PROTEKSI: user tidak boleh menonaktifkan akunnya sendiri (supaya
     * tidak terkunci dari sistem sendiri).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeManager($request);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'role' => ['sometimes', Rule::in(['owner', 'admin', 'kasir'])],
            'is_active' => 'sometimes|boolean',
        ]);

        if (($validated['is_active'] ?? true) === false && $user->id === $request->user()->id) {
            return response()->json([
                'message' => 'Tidak bisa menonaktifkan akun kamu sendiri.',
            ], 422);
        }

        $user->update($validated);

        return response()->json(['data' => $user]);
    }

    /**
     * ASUMSI: hanya owner/admin yang boleh melihat & mengelola daftar user
     * (konsisten dengan komentar create() di AuthController soal
     * CreateUserRequest::canManageUsers). Sesuaikan kalau logic role
     * project kamu berbeda.
     */
    protected function authorizeManager(Request $request): void
    {
        abort_unless(
            in_array($request->user()->role, ['owner', 'admin'], true),
            403,
            'Hanya owner/admin yang boleh mengelola user.'
        );
    }
}
