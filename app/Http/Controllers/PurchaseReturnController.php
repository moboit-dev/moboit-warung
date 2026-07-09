<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Retur pembelian - dipakai saat barang yang sudah di-approve (sudah masuk
 * stok) ternyata rusak/expired/dll dan harus dikembalikan ke supplier.
 * Bisa retur SEBAGIAN qty dari tiap item, dan bisa dilakukan lebih dari
 * sekali untuk item yang sama selama masih ada sisa yang belum diretur.
 *
 * Setiap retur otomatis:
 * - mengurangi stok produk terkait
 * - mencatat StockMovement (type=out) sebagai jejak audit
 * - mewajibkan keterangan (alasan retur, mis. "Expired", "Rusak")
 */
class PurchaseReturnController extends Controller
{
    /**
     * GET /api/purchases/{purchase}/returns
     */
    public function index(Purchase $purchase): JsonResponse
    {
        $returns = $purchase->returns()->with('items.product')->orderByDesc('id')->get();

        return response()->json(['data' => $returns]);
    }

    /**
     * POST /api/purchases/{purchase}/returns
     * Body: {
     *   tanggal_retur: date,
     *   keterangan: string (WAJIB - alasan retur),
     *   items: [{ purchase_item_id, qty }]
     * }
     */
    public function store(Request $request, Purchase $purchase): JsonResponse
    {
        if ($purchase->status !== 'approved') {
            return response()->json([
                'message' => 'Hanya pembelian berstatus approved yang bisa diretur.',
            ], 422);
        }

        $validated = $request->validate([
            'tanggal_retur' => 'required|date',
            'keterangan' => 'required|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.purchase_item_id' => [
                'required',
                Rule::exists('purchase_items', 'id')->where('purchase_id', $purchase->id),
            ],
            'items.*.qty' => 'required|integer|min:1',
        ]);

        try {
            $purchaseReturn = DB::transaction(function () use ($validated, $request, $purchase) {
                $purchaseItems = $purchase->items()->whereIn(
                    'id',
                    collect($validated['items'])->pluck('purchase_item_id')
                )->get()->keyBy('id');

                $total = 0;
                $returnItemsData = [];

                foreach ($validated['items'] as $itemInput) {
                    $purchaseItem = $purchaseItems[$itemInput['purchase_item_id']];
                    $sisa = $purchaseItem->sisaBisaDiretur();

                    if ($itemInput['qty'] > $sisa) {
                        throw new \RuntimeException(
                            "Qty retur untuk produk ID {$purchaseItem->product_id} melebihi sisa yang bisa diretur ({$sisa})."
                        );
                    }

                    $subtotal = $itemInput['qty'] * $purchaseItem->harga_satuan;
                    $total += $subtotal;

                    $returnItemsData[] = [
                        'purchase_item_id' => $purchaseItem->id,
                        'product_id' => $purchaseItem->product_id,
                        'qty' => $itemInput['qty'],
                        'harga_satuan' => $purchaseItem->harga_satuan,
                        'subtotal' => $subtotal,
                    ];
                }

                $purchaseReturn = PurchaseReturn::create([
                    'purchase_id' => $purchase->id,
                    'no_retur' => $this->generateNoRetur(),
                    'tanggal_retur' => $validated['tanggal_retur'],
                    'keterangan' => $validated['keterangan'],
                    'total' => $total,
                    'created_by' => $request->user()->id,
                ]);

                foreach ($returnItemsData as $data) {
                    $purchaseReturn->items()->create($data);

                    // Kurangi stok & catat sebagai movement keluar (out), dengan
                    // note = keterangan yang diisi user (mis. "Expired", "Rusak").
                    $stock = Stock::firstOrCreate(
                        ['product_id' => $data['product_id']],
                        ['quantity' => 0]
                    );

                    if ($stock->quantity < $data['qty']) {
                        throw new \RuntimeException(
                            "Stok produk ID {$data['product_id']} tidak cukup untuk retur sebanyak {$data['qty']}."
                        );
                    }

                    $stock->decrement('quantity', $data['qty']);

                    StockMovement::create([
                        'product_id' => $data['product_id'],
                        'type' => StockMovement::TYPE_OUT,
                        'quantity' => -$data['qty'],
                        'note' => 'Retur pembelian '.$purchase->no_pembelian.' - '.$validated['keterangan'],
                        'reference_id' => $purchaseReturn->id,
                        'created_by' => $request->user()->id,
                    ]);
                }

                return $purchaseReturn;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseReturn->load('items.product');

        return response()->json(['data' => $purchaseReturn], 201);
    }

    protected function generateNoRetur(): string
    {
        $prefix = 'RB-'.now()->format('Ymd').'-';
        $lastNumber = PurchaseReturn::where('no_retur', 'like', $prefix.'%')->count();

        return $prefix.str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    }
}
