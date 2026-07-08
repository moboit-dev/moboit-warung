<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * ASUMSI: status transaksi yang dihitung sebagai penjualan sah adalah
     * 'completed'. Kalau di project kamu nilainya berbeda (mis. 'paid',
     * 'success'), ganti array ini.
     */
    protected const COMPLETED_STATUSES = ['completed'];

    /**
     * GET /api/reports/sales?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     * Dipakai Dashboard & Laporan Penjualan (report_provider.dart) —
     * mengembalikan breakdown PER HARI dalam rentang tanggal, bentuknya
     * List, BUKAN satu object ringkasan. Nama field JSON diasumsikan
     * snake_case (date, total_transactions, total_revenue, total_profit)
     * mengikuti fromJson di models/sales_report.dart — sesuaikan kalau
     * ternyata beda.
     *
     * total_profit dihitung dari cost_price PRODUK SAAT INI (bukan
     * snapshot harga saat transaksi terjadi), karena TransactionItem
     * tidak menyimpan cost_price historis. Untuk laporan lama yang
     * cost_price produknya sudah berubah, angka untung ini perkiraan,
     * bukan angka pasti.
     *
     * PENTING: profit dihitung dari harga jual BERSIH per baris
     * (price * quantity - discount_amount), bukan dari price * quantity
     * saja. Kalau tidak, baris yang kena diskon promo (termasuk bonus
     * item BOGO yang discount_amount-nya menutup seluruh harga jual)
     * akan membuat total_profit ke-overstate, karena cost_price produk
     * tetap dikurangkan penuh padahal barangnya dijual murah/gratis.
     */
    public function sales(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();
        $tenantId = $request->user()->tenant_id;

        // Revenue & jumlah transaksi per hari — pakai Eloquent supaya
        // global scope tenant dari BelongsToTenant otomatis jalan.
        $revenueRows = Transaction::query()
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total_transactions, COALESCE(SUM(total), 0) as total_revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Profit per hari butuh join manual ke transactions & products.
        // Query manual seperti ini TIDAK melewati global scope Eloquent,
        // jadi tenant_id difilter eksplisit di sini.
        $profitRows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_items.product_id')
            ->where('transactions.tenant_id', $tenantId)
            ->whereBetween('transactions.created_at', [$dateFrom, $dateTo])
            ->whereIn('transactions.status', self::COMPLETED_STATUSES)
            ->selectRaw('DATE(transactions.created_at) as date, SUM(
                (transaction_items.price * transaction_items.quantity)
                - transaction_items.discount_amount
                - (products.cost_price * transaction_items.quantity)
            ) as total_profit')
            ->groupBy(DB::raw('DATE(transactions.created_at)'))
            ->pluck('total_profit', 'date');

        $data = [];
        $cursor = $dateFrom->copy();

        // Isi semua tanggal dalam rentang, termasuk yang tidak ada transaksi
        // sama sekali (total 0) — supaya tabel di Flutter tidak bolong.
        while ($cursor->lte($dateTo)) {
            $key = $cursor->toDateString();
            $revenueRow = $revenueRows->get($key);

            $data[] = [
                'date' => $key,
                'total_transactions' => $revenueRow ? (int) $revenueRow->total_transactions : 0,
                'total_revenue' => $revenueRow ? (float) $revenueRow->total_revenue : 0.0,
                'total_profit' => isset($profitRows[$key]) ? (float) $profitRows[$key] : 0.0,
            ];

            $cursor->addDay();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/reports/sales/transactions?date=YYYY-MM-DD
     *   ATAU
     * GET /api/reports/sales/transactions?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     *
     * Dipakai di DUA tempat:
     *  1) Layar Ringkasan, pas baris tanggal di-expand — kirim `date` saja
     *     (satu hari).
     *  2) Layar Laporan Detail, jenis "Per Struk" — kirim `date_from` +
     *     `date_to` (bisa rentang beberapa hari), balikin SEMUA struk di
     *     rentang itu (tidak dikelompokkan per tanggal; itu bedanya dengan
     *     Ringkasan yang sudah punya pengelompokan sendiri per baris).
     *
     * SENGAJA bentuk JSON tiap transaksi dibuat SAMA PERSIS dengan yang
     * dibalikin TransactionController::store() (`$transaction->load('items')`)
     * — supaya model `Receipt`/`ReceiptItem` yang sudah ada di Flutter
     * (widgets/receipt_dialog.dart) bisa dipakai ulang buat parsing,
     * tidak perlu model baru.
     */
    public function salesTransactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'date_from' => 'nullable|date|required_without:date',
            'date_to' => 'nullable|date|required_without:date|after_or_equal:date_from',
        ]);

        if (! empty($validated['date'])) {
            $dateStart = Carbon::parse($validated['date'])->startOfDay();
            $dateEnd = $dateStart->copy()->endOfDay();
        } else {
            $dateStart = Carbon::parse($validated['date_from'])->startOfDay();
            $dateEnd = Carbon::parse($validated['date_to'])->endOfDay();
        }

        $transactions = Transaction::query()
            ->with('items')
            ->whereBetween('created_at', [$dateStart, $dateEnd])
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $transactions]);
    }

    /**
     * GET /api/reports/sales/items?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     * Laporan Detail jenis "Per Item" — agregat per PRODUK (bukan per
     * struk): total qty terjual & omzet bersih (setelah diskon) selama
     * rentang tanggal. Dikelompokkan pakai product_id + product_name yang
     * TERSIMPAN di transaction_items (snapshot nama saat transaksi terjadi),
     * bukan join ke tabel products — supaya produk yang sudah dihapus/ganti
     * nama tetap muncul benar di laporan lama, konsisten dengan pendekatan
     * yang sama dipakai `items()` relation di TransactionController::store().
     */
    public function salesByItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();
        $tenantId = $request->user()->tenant_id;

        $rows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->where('transactions.tenant_id', $tenantId)
            ->whereBetween('transactions.created_at', [$dateFrom, $dateTo])
            ->whereIn('transactions.status', self::COMPLETED_STATUSES)
            ->selectRaw('transaction_items.product_id,
                transaction_items.product_name,
                SUM(transaction_items.quantity) as total_quantity,
                SUM((transaction_items.price * transaction_items.quantity) - transaction_items.discount_amount) as total_revenue')
            ->groupBy('transaction_items.product_id', 'transaction_items.product_name')
            ->orderByDesc('total_revenue')
            ->get();

        $data = $rows->map(fn ($r) => [
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'total_quantity' => (int) $r->total_quantity,
            'total_revenue' => (float) $r->total_revenue,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/reports/sales/categories?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     * Laporan Detail jenis "Per Kategori" — agregat per KATEGORI produk.
     *
     * ASUMSI: tabel `categories` punya kolom `name`, dan `products` punya
     * FK `category_id` (konsisten dengan `CartItem(categoryId: $product?->
     * category_id)` yang sudah dipakai di TransactionController). Kalau
     * nama kolom/tabel beda, sesuaikan join di bawah.
     *
     * CAVEAT (sama seperti profit di method `sales()` di atas): kategori
     * diambil dari `products.category_id` PRODUK SAAT INI, bukan snapshot
     * kategori saat transaksi terjadi (transaction_items tidak menyimpan
     * category_id historis). Kalau produk pernah pindah kategori, laporan
     * lama akan ikut kategori yang BARU, bukan yang berlaku saat itu.
     *
     * Item yang produknya sudah dihapus (product_id tidak match ke
     * `products` manapun) dikelompokkan sebagai "Tanpa Kategori" lewat
     * LEFT JOIN + COALESCE, supaya tidak hilang diam-diam dari total.
     */
    public function salesByCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $dateFrom = Carbon::parse($validated['date_from'])->startOfDay();
        $dateTo = Carbon::parse($validated['date_to'])->endOfDay();
        $tenantId = $request->user()->tenant_id;

        $rows = TransactionItem::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_items.transaction_id')
            ->leftJoin('products', 'products.id', '=', 'transaction_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('transactions.tenant_id', $tenantId)
            ->whereBetween('transactions.created_at', [$dateFrom, $dateTo])
            ->whereIn('transactions.status', self::COMPLETED_STATUSES)
            ->selectRaw('COALESCE(categories.id, 0) as category_id,
                COALESCE(categories.name, "Tanpa Kategori") as category_name,
                SUM(transaction_items.quantity) as total_quantity,
                SUM((transaction_items.price * transaction_items.quantity) - transaction_items.discount_amount) as total_revenue')
            ->groupBy('category_id', 'category_name')
            ->orderByDesc('total_revenue')
            ->get();

        $data = $rows->map(fn ($r) => [
            'category_id' => (int) $r->category_id,
            'category_name' => $r->category_name,
            'total_quantity' => (int) $r->total_quantity,
            'total_revenue' => (float) $r->total_revenue,
        ]);

        return response()->json(['data' => $data]);
    }
}
