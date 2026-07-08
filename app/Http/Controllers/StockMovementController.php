<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockMovementController extends Controller
{
    /**
     * GET /api/stock-movements?product_id=<id opsional>
     * Dipakai stockMovementListProvider (family provider, product_id bisa
     * null untuk semua movement, atau diisi untuk histori 1 produk).
     */
    public function index(Request $request): JsonResponse
    {
        $movements = StockMovement::query()
            ->when($request->filled('product_id'), function ($query) use ($request) {
                $query->where('product_id', $request->input('product_id'));
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $movements]);
    }

    /**
     * POST /api/stock-movements
     * Body: { product_id, type: 'in'|'out'|'adjustment', unit: 'besar'|'kecil',
     *         quantity (SIGNED, boleh negatif), note? }
     *
     * Dipakai stock_provider.dart -> adjust() untuk koreksi manual / input
     * barang masuk. Beda dari endpoint /api/stocks/{product}/adjust yang
     * sebelumnya kubuat (itu sekarang tidak dipakai UI, boleh diabaikan
     * atau dihapus kalau mau).
     *
     * type 'break_unit' SENGAJA tidak diizinkan di sini — itu cuma boleh
     * tercipta otomatis dari TransactionController saat auto-break unit
     * besar->kecil, bukan dari input manual.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'type' => ['required', Rule::in([StockMovement::TYPE_IN, StockMovement::TYPE_OUT, StockMovement::TYPE_ADJUSTMENT])],
            'unit' => ['required', Rule::in([StockMovement::UNIT_BESAR, StockMovement::UNIT_KECIL])],
            'quantity' => 'required|integer|not_in:0',
            'note' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        if ($validated['unit'] === StockMovement::UNIT_BESAR && ! $product->hasMultiUnit()) {
            return response()->json([
                'message' => 'Produk ini bukan produk multi-unit, tidak punya satuan besar.',
            ], 422);
        }

        try {
            $movement = DB::transaction(function () use ($request, $validated, $product) {
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

                return StockMovement::create([
                    'product_id' => $product->id,
                    'type' => $validated['type'],
                    'unit' => $validated['unit'],
                    'quantity' => $validated['quantity'],
                    'note' => $validated['note'] ?? null,
                    'created_by' => $request->user()->id,
                ]);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $movement], 201);
    }
}
