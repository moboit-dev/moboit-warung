<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Stock;
use App\Models\SupplierDebt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    /**
     * GET /api/purchases
     */
    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::query()
            ->with('supplier')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->input('supplier_id')))
            ->when($request->filled('tanggal_mulai'), fn ($q) => $q->whereDate('tanggal_pembelian', '>=', $request->input('tanggal_mulai')))
            ->when($request->filled('tanggal_akhir'), fn ($q) => $q->whereDate('tanggal_pembelian', '<=', $request->input('tanggal_akhir')))
            ->orderByDesc('tanggal_pembelian')
            ->paginate($request->integer('per_page', 20));

        return response()->json($purchases);
    }

    /**
     * POST /api/purchases
     * Simpan sebagai draft. Stok BELUM bertambah di sini - baru bertambah
     * saat approve() dipanggil (lihat catatan di method approve()).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tanggal_pembelian' => 'required|date',
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')],
            'jenis_pembayaran' => 'required|in:cash,transfer,kredit',
            'jatuh_tempo' => 'nullable|date|required_if:jenis_pembayaran,kredit',
            'catatan' => 'nullable|string',
            'bukti_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')],
            'items.*.satuan_dibeli' => 'required|in:besar,kecil',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.harga_satuan' => 'required|numeric|min:0',
        ]);

        $purchase = DB::transaction(function () use ($validated, $request) {
            $total = collect($validated['items'])->sum(fn ($item) => $item['qty'] * $item['harga_satuan']);

            $buktiPath = null;
            if ($request->hasFile('bukti_file')) {
                $buktiPath = $request->file('bukti_file')->store('bukti-pembelian', 'public');
            }

            $purchase = Purchase::create([
                'no_pembelian' => $this->generateNoPembelian(),
                'tanggal_pembelian' => $validated['tanggal_pembelian'],
                'supplier_id' => $validated['supplier_id'],
                'jenis_pembayaran' => $validated['jenis_pembayaran'],
                'jatuh_tempo' => $validated['jatuh_tempo'] ?? null,
                'status' => 'draft',
                'total' => $total,
                'bukti_file' => $buktiPath,
                'catatan' => $validated['catatan'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                $product = Product::findOrFail($item['product_id']);

                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'satuan_dibeli' => $item['satuan_dibeli'],
                    'qty' => $item['qty'],
                    'conversion_qty_snapshot' => $product->conversion_qty,
                    'harga_satuan' => $item['harga_satuan'],
                    'subtotal' => $item['qty'] * $item['harga_satuan'],
                ]);
            }

            return $purchase;
        });

        $purchase->load(['supplier', 'items.product']);

        return response()->json(['data' => $purchase], 201);
    }

    /**
     * GET /api/purchases/{purchase}
     */
    public function show(Purchase $purchase): JsonResponse
    {
        $purchase->load(['supplier', 'items.product']);

        return response()->json(['data' => $purchase]);
    }

    /**
     * POST /api/purchases/{purchase}/approve
     * Di sinilah stok BENAR-BENAR bertambah - qty_besar / qty_kecil sesuai
     * satuan_dibeli tiap item, TANPA konversi (box & sachet dihitung terpisah,
     * sama seperti catatan desain di migration purchase_items).
     *
     * TODO: kalau butuh audit trail per pergerakan stok, catat juga ke
     * StockMovement di sini (satu baris per item) - belum diimplementasikan
     * karena skema stock_movements belum dikonfirmasi.
     *
     * Kalau jenis_pembayaran = kredit, sekaligus bikin baris SupplierDebt.
     */
    public function approve(Request $request, Purchase $purchase): JsonResponse
    {
        if ($purchase->status !== 'draft') {
            return response()->json(['message' => 'Hanya pembelian berstatus draft yang bisa di-approve.'], 422);
        }

        DB::transaction(function () use ($request, $purchase) {
            foreach ($purchase->items as $item) {
                $stock = Stock::firstOrCreate(
                    ['product_id' => $item->product_id],
                    ['quantity' => 0, 'qty_besar' => 0, 'qty_kecil' => 0]
                );

                if ($item->satuan_dibeli === 'besar') {
                    $stock->increment('qty_besar', $item->qty);
                } else {
                    $stock->increment('qty_kecil', $item->qty);
                }
            }

            $purchase->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            if ($purchase->jenis_pembayaran === 'kredit') {
                SupplierDebt::create([
                    'purchase_id' => $purchase->id,
                    'supplier_id' => $purchase->supplier_id,
                    'jumlah' => $purchase->total,
                    'sisa' => $purchase->total,
                    'jatuh_tempo' => $purchase->jatuh_tempo,
                    'status' => 'belum_lunas',
                ]);
            }
        });

        $purchase->load(['supplier', 'items.product']);

        return response()->json(['data' => $purchase]);
    }

    /**
     * POST /api/purchases/{purchase}/cancel
     */
    public function cancel(Purchase $purchase): JsonResponse
    {
        if ($purchase->status !== 'draft') {
            return response()->json(['message' => 'Hanya pembelian berstatus draft yang bisa dibatalkan.'], 422);
        }

        $purchase->update(['status' => 'cancelled']);

        return response()->json(['data' => $purchase]);
    }

    /**
     * GET /api/purchases/{purchase}/pdf
     * TODO: generate PDF sungguhan (mis. pakai package barryvdh/laravel-dompdf).
     * Untuk sekarang endpoint sudah tersedia tapi belum generate file asli,
     * supaya Flutter tidak lagi kena 404 - selanjutnya tinggal isi logic di sini.
     */
    public function pdf(Purchase $purchase)
    {
        abort(501, 'Generate PDF bukti pembelian belum diimplementasikan.');
    }

    protected function generateNoPembelian(): string
    {
        $prefix = 'PB-'.now()->format('Ymd').'-';
        $lastNumber = Purchase::where('no_pembelian', 'like', $prefix.'%')->count();

        return $prefix.str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    }
}
