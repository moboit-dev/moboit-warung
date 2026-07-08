<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CATATAN DESAIN:
 * Sengaja TIDAK ada endpoint `update()` biasa di sini. Berdasarkan komentar
 * di StockMovement, setiap perubahan quantity harus tercatat sebagai
 * movement (in/out/adjustment/break_unit) untuk keperluan audit & laporan.
 * Kalau Stock::update() langsung dipanggil dari controller, historinya
 * hilang dan totalDalamSatuanKecil() jadi tidak bisa direkonsiliasi.
 *
 * Endpoint di sini hanya READ (index/show) + `adjust` (koreksi stok manual,
 * mis. stok opname). Alur jual/beli/auto-break unit besar->kecil
 * (StockService::sellKecilDenganAutoBreak yang disebut di komentar
 * StockMovement) BELUM dibuat di sini — itu logic bisnis terpisah yang
 * lebih baik dibuatkan service class sendiri, bukan langsung di controller.
 */
class StockController extends Controller
{
    /**
     * GET /api/stocks
     * List stok semua produk milik tenant yang login.
     * ?low_stock=1 untuk filter produk yang stoknya di bawah threshold
     * (pakai qty_kecil karena itu satuan dasar untuk multi-unit).
     */
    public function index(Request $request): JsonResponse
    {
        $stocks = Stock::query()
            ->with('product:id,name,sku,unit_besar,unit_kecil,conversion_qty')
            ->whereHas('product', function ($query) use ($request) {
                if ($request->filled('search')) {
                    $query->where('name', 'like', '%' . $request->input('search') . '%')
                        ->orWhere('sku', 'like', '%' . $request->input('search') . '%');
                }
            })
            ->when($request->filled('low_stock_threshold'), function ($query) use ($request) {
                $query->where('qty_kecil', '<=', $request->integer('low_stock_threshold'));
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
                'product' => $product->only('id', 'name', 'sku', 'unit_besar', 'unit_kecil', 'conversion_qty'),
                'stock' => $stock,
                'total_dalam_satuan_kecil' => $stock->totalDalamSatuanKecil(),
                'recent_movements' => $recentMovements,
            ],
        ]);
    }

    /**
     * POST /api/stocks/{product}/adjust
     * Body: { unit: 'besar'|'kecil', quantity: int (boleh negatif untuk koreksi turun), note?: string }
     *
     * Dipakai untuk stok opname / koreksi manual. Selalu tercatat sebagai
     * StockMovement type=adjustment supaya kelihatan di histori siapa
     * ubah apa kapan (created_by diisi dari user yang login).
     */
    public function adjust(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'unit' => ['required', Rule::in([StockMovement::UNIT_BESAR, StockMovement::UNIT_KECIL])],
            'quantity' => 'required|integer|not_in:0',
            'note' => 'nullable|string|max:255',
        ]);

        if (! $product->track_stock) {
            return response()->json([
                'message' => 'Produk ini tidak melacak stok (track_stock = false).',
            ], 422);
        }

        if ($validated['unit'] === StockMovement::UNIT_BESAR && ! $product->hasMultiUnit()) {
            return response()->json([
                'message' => 'Produk ini bukan produk multi-unit, tidak punya satuan besar.',
            ], 422);
        }

        $stock = DB::transaction(function () use ($request, $product, $validated) {
            $stock = Stock::firstOrCreate(
                ['product_id' => $product->id],
                ['quantity' => 0, 'qty_besar' => 0, 'qty_kecil' => 0]
            );

            $column = $validated['unit'] === StockMovement::UNIT_BESAR ? 'qty_besar' : 'qty_kecil';
            $stock->{$column} += $validated['quantity'];

            if ($stock->{$column} < 0) {
                throw new \RuntimeException("Stok {$validated['unit']} tidak boleh minus.");
            }

            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'unit' => $validated['unit'],
                'quantity' => $validated['quantity'],
                'note' => $validated['note'] ?? 'Koreksi stok manual',
                'created_by' => $request->user()->id,
            ]);

            return $stock;
        });

        return response()->json(['data' => $stock]);
    }
}
