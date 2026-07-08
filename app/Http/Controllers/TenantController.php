<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * PUT /api/tenant/settings
     * Update nama & alamat toko milik tenant yang sedang login.
     * Tidak ada endpoint GET terpisah — Flutter ambil data awal dari
     * authProvider (data tenant hasil login), bukan fetch ulang di sini.
     *
     * ASUMSI: hanya owner yang boleh ubah setting toko (bukan admin/kasir).
     * Kalau admin juga boleh, ganti array di authorizeOwner().
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'address' => 'nullable|string|max:255',
        ]);

        $tenant = $request->user()->tenant;
        $tenant->update($validated);

        return response()->json(['data' => $tenant]);
    }

    protected function authorizeOwner(Request $request): void
    {
        abort_unless(
            $request->user()->role === 'owner',
            403,
            'Hanya owner yang boleh mengubah setting toko.'
        );
    }
}
