<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CATATAN DESAIN:
 * Sengaja TIDAK ada endpoint `update()` biasa di sini. Setiap perubahan
 * quantity harus tercatat sebagai StockMovement (in/out/adjustment) untuk
 * keperluan audit & laporan. Endpoint di sini hanya READ (index/show) +
 * `adjust` (koreksi stok manual, mis. stok opname).
 *
 * Multi-unit (satuan besar/kecil) sudah dihapus - stok kini cuma
 * satu kolom `quantity` per produk.
 */
class StockController extends Controller
{
    /**
     * GET /api/stocks
     * ?search= nama/sku produk
     * ?low_stock_threshold= filter produk yang stoknya <= threshold
     */
    public function index(Request $request): JsonResponse
    {
        $stocks = Stock::query()
            ->with('product:id,name,sku,unit')
            ->whereHas('product', function ($query) use ($request) {
                if ($request->filled('search')) {
                    $query->where('name', 'like', '%'.$request->input('search').'%')
                        ->orWhere('sku', 'like', '%'.$request->input('search').'%');
                }
            })
            ->when($request->filled('low_stock_threshold'), function ($query) use ($request) {
                $query->where('quantity', '<=', $request->integer('low_stock_threshold'));
            })
            ->paginate($request->integer('per_page', 20));

        return response()->json($stocks);
    }

    /**
     * GET /api/stocks/{product}
     * Lihat stok + histori movement terbaru untuk satu produk.
     */
    public function show(Product $product): JsonResponse
    {
        $stock = $product->stock;

        if (! $stock) {
            return response()->json([
                'message' => 'Produk ini belum punya baris stok (kemungkinan track_stock = false).',
            ], 404);
        }

        $recentMovements = StockMovement::where('product_id', $product->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => [
                'product' => $product->only('id', 'name', 'sku', 'unit'),
                'stock' => $stock,
                'recent_movements' => $recentMovements,
            ],
        ]);
    }

    /**
     * POST /api/stocks/{product}/adjust
     * Body: { quantity: int (boleh negatif untuk koreksi turun), note?: string }
     *
     * Dipakai untuk stok opname / koreksi manual. Selalu tercatat sebagai
     * StockMovement type=adjustment supaya kelihatan di histori siapa
     * ubah apa kapan (created_by diisi dari user yang login).
     */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|not_in:0',
            'note' => 'nullable|string|max:255',
        ]);

        if (! $product->track_stock) {
            return response()->json([
                'message' => 'Produk ini tidak melacak stok (track_stock = false).',
            ], 422);
        }

        try {
            $stock = DB::transaction(function () use ($request, $product, $validated) {
                $stock = Stock::firstOrCreate(
                    ['product_id' => $product->id],
                    ['quantity' => 0]
                );

                $stock->quantity += $validated['quantity'];

                if ($stock->quantity < 0) {
                    throw new \RuntimeException('Stok tidak boleh minus.');
                }

                $stock->save();

                StockMovement::create([
                    'product_id' => $product->id,
                    'type' => StockMovement::TYPE_ADJUSTMENT,
                    'quantity' => $validated['quantity'],
                    'note' => $validated['note'] ?? 'Koreksi stok manual',
                    'created_by' => $request->user()->id,
                ]);

                return $stock;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $stock]);
    }
}
