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
     * Body: { product_id, type: 'in'|'out'|'adjustment', quantity (SIGNED), note? }
     *
     * Dipakai untuk koreksi manual / input barang masuk di luar alur
     * pembelian (mis. retur dari pelanggan, penyesuaian gudang).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'type' => ['required', Rule::in([StockMovement::TYPE_IN, StockMovement::TYPE_OUT, StockMovement::TYPE_ADJUSTMENT])],
            'quantity' => 'required|integer|not_in:0',
            'note' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($validated['product_id']);

        try {
            $movement = DB::transaction(function () use ($request, $validated, $product) {
                $stock = Stock::firstOrCreate(
                    ['product_id' => $product->id],
                    ['quantity' => 0]
                );

                $stock->quantity += $validated['quantity'];

                if ($stock->quantity < 0) {
                    throw new \RuntimeException('Stok tidak boleh minus.');
                }

                $stock->save();

                return StockMovement::create([
                    'product_id' => $product->id,
                    'type' => $validated['type'],
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
