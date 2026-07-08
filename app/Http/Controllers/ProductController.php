<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * GET /api/products
     * Dipakai kasir buat "Cari produk / scan barcode" -> search mencakup
     * name, sku (barcode), dan filter category_id (dropdown kategori).
     */
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->with('category')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->input('category_id'));
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($products);
    }

    /**
     * POST /api/products
     * tenant_id TIDAK dikirim manual, biar diisi otomatis oleh
     * BelongsToTenant. category_id divalidasi harus milik tenant yang
     * sama (Rule::exists otomatis ter-scope karena Category juga pakai
     * global scope tenant saat query exists-nya dijalankan lewat model).
     *
     * Kalau track_stock true, sekalian bikin baris Stock awal (default 0)
     * supaya endpoint Stok langsung punya baris untuk produk ini —
     * tidak perlu langkah "buat stok" terpisah setelah bikin produk.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $initialStock = $request->integer('initial_stock', 0);

        $product = DB::transaction(function () use ($validated, $initialStock) {
            $product = Product::create($validated);

            if ($product->track_stock) {
                Stock::create([
                    'product_id' => $product->id,
                    'quantity' => 0,
                    'qty_besar' => 0,
                    'qty_kecil' => $initialStock,
                ]);
            }

            return $product;
        });

        $product->load('category');

        return response()->json(['data' => $product], 201);
    }

    /**
     * GET /api/products/{product}
     */
    public function show(Product $product): JsonResponse
    {
        $product->load(['category', 'stock']);

        return response()->json(['data' => $product]);
    }

    /**
     * PUT/PATCH /api/products/{product}
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $this->validated($request, $product->id);

        $product->update($validated);
        $product->load('category');

        return response()->json(['data' => $product]);
    }

    /**
     * DELETE /api/products/{product}
     * Soft delete saja — histori transaksi/stock movement yang mereferensikan
     * product_id ini tetap harus bisa ditelusuri.
     */
    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
            'name' => 'required|string|max:150',
            'sku' => [
                'nullable', 'string', 'max:100',
                Rule::unique('products', 'sku')->ignore($ignoreId)->where(fn ($q) => $q->whereNull('deleted_at')),
            ],
            'cost_price' => 'nullable|numeric|min:0',
            'price' => 'required|numeric|min:0',
            'type' => 'nullable|string|max:50',
            'track_stock' => 'boolean',
            'unit_besar' => 'nullable|string|max:50',
            'unit_kecil' => 'nullable|string|max:50',
            'conversion_qty' => 'nullable|integer|min:1',
            'price_besar' => 'nullable|numeric|min:0',
        ]);
    }
}
