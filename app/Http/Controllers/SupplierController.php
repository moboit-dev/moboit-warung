<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * GET /api/suppliers
     * Dipakai form pembelian buat pilih supplier - search sederhana by nama.
     */
    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('nama', 'like', '%'.$request->input('search').'%');
            })
            ->orderBy('nama')
            ->get();

        return response()->json(['data' => $suppliers]);
    }

    /**
     * POST /api/suppliers
     * tenant_id otomatis diisi oleh BelongsToTenant, sama seperti Product.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $supplier = Supplier::create($validated);

        return response()->json(['data' => $supplier], 201);
    }

    /**
     * GET /api/suppliers/{supplier}
     */
    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    /**
     * PUT/PATCH /api/suppliers/{supplier}
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $this->validated($request);

        $supplier->update($validated);

        return response()->json(['data' => $supplier]);
    }

    /**
     * DELETE /api/suppliers/{supplier}
     */
    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(null, 204);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'nama' => 'required|string|max:150',
            'kontak' => 'nullable|string|max:50',
            'alamat' => 'nullable|string',
            'catatan' => 'nullable|string',
        ]);
    }
}
