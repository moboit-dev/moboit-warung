<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\SupplierDebt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    /**
     * GET /api/purchases
     *
     * Filter yang didukung:
     * - status
     * - supplier_id
     * - tanggal_mulai / tanggal_akhir (rentang tanggal)
     * - tanggal (tanggal persis)
     * - no_pembelian (cocok sebagian, mis. cari "0002")
     * - search (bebas: cocok di no_pembelian ATAU nama item yang dibeli)
     */
    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::query()
            ->with('supplier')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->input('supplier_id')))
            ->when($request->filled('tanggal_mulai'), fn ($q) => $q->whereDate('tanggal_pembelian', '>=', $request->input('tanggal_mulai')))
            ->when($request->filled('tanggal_akhir'), fn ($q) => $q->whereDate('tanggal_pembelian', '<=', $request->input('tanggal_akhir')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('tanggal_pembelian', $request->input('tanggal')))
            ->when($request->filled('no_pembelian'), fn ($q) => $q->where('no_pembelian', 'like', '%'.$request->input('no_pembelian').'%'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->input('search');
                $q->where(function ($sub) use ($search) {
                    $sub->where('no_pembelian', 'like', '%'.$search.'%')
                        ->orWhereHas('items.product', function ($iq) use ($search) {
                            $iq->where('name', 'like', '%'.$search.'%');
                        })
                        ->orWhereHas('supplier', function ($sq) use ($search) {
                            $sq->where('nama', 'like', '%'.$search.'%');
                        });
                });
            })
            ->orderByDesc('tanggal_pembelian')
            ->paginate($request->integer('per_page', 20));

        return response()->json($purchases);
    }

    /**
     * POST /api/purchases
     * Simpan sebagai draft. Stok BELUM bertambah di sini - baru bertambah
     * saat approve() dipanggil.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePurchaseData($request);

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
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
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
        $purchase->load(['supplier', 'items.product', 'returns.items.product']);

        return response()->json(['data' => $purchase]);
    }

    /**
     * PUT /api/purchases/{purchase}
     * Edit pembelian - HANYA boleh selama status masih 'draft'. Begitu
     * approved, stok sudah terlanjur bertambah & mungkin sudah ada hutang
     * supplier terkait, jadi edit langsung tidak aman lagi (dan sengaja
     * tidak diizinkan; kalau ada kesalahan setelah approve, alurnya lewat
     * fitur retur, bukan edit).
     */
    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        if ($purchase->status !== 'draft') {
            return response()->json([
                'message' => 'Hanya pembelian berstatus draft yang bisa diedit.',
            ], 422);
        }

        $validated = $this->validatePurchaseData($request);

        DB::transaction(function () use ($validated, $request, $purchase) {
            $total = collect($validated['items'])->sum(fn ($item) => $item['qty'] * $item['harga_satuan']);

            $buktiPath = $purchase->bukti_file;
            if ($request->hasFile('bukti_file')) {
                if ($buktiPath) {
                    Storage::disk('public')->delete($buktiPath);
                }
                $buktiPath = $request->file('bukti_file')->store('bukti-pembelian', 'public');
            }

            $purchase->update([
                'tanggal_pembelian' => $validated['tanggal_pembelian'],
                'supplier_id' => $validated['supplier_id'],
                'jenis_pembayaran' => $validated['jenis_pembayaran'],
                'jatuh_tempo' => $validated['jatuh_tempo'] ?? null,
                'total' => $total,
                'bukti_file' => $buktiPath,
                'catatan' => $validated['catatan'] ?? null,
            ]);

            // Ganti seluruh item lama dengan item baru (lebih sederhana &
            // aman daripada diff satu-satu, karena masih draft / stok belum jalan).
            $purchase->items()->delete();
            foreach ($validated['items'] as $item) {
                $purchase->items()->create([
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'harga_satuan' => $item['harga_satuan'],
                    'subtotal' => $item['qty'] * $item['harga_satuan'],
                ]);
            }
        });

        $purchase->load(['supplier', 'items.product']);

        return response()->json(['data' => $purchase]);
    }

    /**
     * POST /api/purchases/{purchase}/approve
     * Stok bertambah di sini, sekaligus dicatat sebagai StockMovement
     * (type=in) per item supaya ada jejak audit yang bisa ditelusuri di
     * histori/laporan stok.
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
                    ['quantity' => 0]
                );

                $stock->increment('quantity', $item->qty);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => StockMovement::TYPE_IN,
                    'quantity' => $item->qty,
                    'note' => 'Pembelian '.$purchase->no_pembelian,
                    'reference_id' => $purchase->id,
                    'created_by' => $request->user()->id,
                ]);
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
     */
    public function pdf(Purchase $purchase)
    {
        abort(501, 'Generate PDF bukti pembelian belum diimplementasikan.');
    }

    protected function validatePurchaseData(Request $request): array
    {
        return $request->validate([
            'tanggal_pembelian' => 'required|date',
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')],
            'jenis_pembayaran' => 'required|in:cash,transfer,kredit',
            'jatuh_tempo' => 'nullable|date|required_if:jenis_pembayaran,kredit',
            'catatan' => 'nullable|string',
            'bukti_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')],
            'items.*.qty' => 'required|integer|min:1',
            'items.*.harga_satuan' => 'required|numeric|min:0',
        ]);
    }

    protected function generateNoPembelian(): string
    {
        $prefix = 'PB-'.now()->format('Ymd').'-';
        $lastNumber = Purchase::where('no_pembelian', 'like', $prefix.'%')->count();

        return $prefix.str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    }
}
