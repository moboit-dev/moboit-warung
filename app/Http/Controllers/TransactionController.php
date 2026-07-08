<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\Promotion\CartItem;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function __construct(protected PromotionService $promotionService)
    {
    }

    /**
     * POST /api/transactions
     * Body: { payment_method, paid?, items: [{ product_id, unit, quantity, price }] }
     *
     * ASUMSI: UI kasir (pos_screen.dart) belum punya input "uang diterima",
     * jadi kalau `paid` tidak dikirim, dianggap PAS (paid = total, change = 0).
     * Kalau nanti UI menambahkan input tunai, tinggal kirim `paid` di body.
     *
     * ASUMSI: transaction_type = 'sale' dan status = 'completed' untuk
     * transaksi normal dari kasir (konsisten dengan status yang dipakai
     * ReportController).
     *
     * PENTING (bug yang diperbaiki di sini): sebelumnya `$total` dihitung
     * murni dari `sum(price * quantity)` yang dikirim body request — promo
     * tidak pernah diikutsertakan, jadi transaksi selalu tercatat dengan
     * harga normal walau ada promo yang seharusnya aktif. Sekarang
     * `PromotionService::evaluate()` dipanggil ulang di server (persis
     * logic yang sama dipakai `POST /api/promotions/evaluate-cart`)
     * sebelum `Transaction` dibuat, dan hasilnya (subtotal, discount,
     * grand_total, bonus item) yang dipakai untuk `total` & baris item —
     * bukan hasil sum polos dari request.
     *
     * ASUMSI: `price` yang dikirim client tetap dipakai sebagai harga
     * satuan (perilaku ini sudah ada sebelumnya di controller ini, tidak
     * diubah di patch ini) — hanya `category_id` yang diambil ulang dari
     * tabel `products` supaya promo per-kategori bisa dievaluasi dengan
     * benar. Kalau kamu mau harga juga tidak dipercaya dari client sama
     * sekali, ganti baris `price: (float) $item['price']` di
     * `buildCartItems()` jadi mengambil harga dari `$product->price` /
     * `$product->price_besar` sesuai unit.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', Rule::in(['cash', 'qris', 'transfer'])],
            'paid' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.unit' => ['required', Rule::in([StockMovement::UNIT_BESAR, StockMovement::UNIT_KECIL])],
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        // Ambil semua produk yang dipakai di keranjang sekali query (bukan
        // findOrFail per item di dalam loop seperti sebelumnya) — hasilnya
        // dipakai baik untuk evaluasi promo maupun untuk membuat baris item.
        $productsById = Product::query()
            ->whereIn('id', collect($validated['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $cartItems = $this->buildCartItems($validated['items'], $productsById);

        // Evaluasi promo di LUAR DB::transaction karena murni read-only —
        // kalau tidak ada promo aktif, $promoResult->grandTotal() akan
        // sama saja dengan sum(price*qty) seperti sebelumnya.
        $promoResult = $this->promotionService->evaluate($tenantId, $cartItems);

        try {
            $transaction = DB::transaction(function () use ($request, $validated, $promoResult, $productsById) {
                $subtotal = $promoResult->subtotal;
                $total = $promoResult->grandTotal();
                $discountAmount = $subtotal - $total;

                $paid = $validated['paid'] ?? $total;

                $transaction = Transaction::create([
                    'user_id' => $request->user()->id,
                    'transaction_number' => $this->generateTransactionNumber(),
                    'transaction_type' => 'sale',
                    // BUG YANG DIPERBAIKI: payment_method sudah lolos validasi
                    // ($request->validate() di atas) tapi sebelumnya TIDAK PERNAH
                    // disimpan ke kolom ini — jadi selalu null di database,
                    // walau kasir pilih Tunai/QRIS/Transfer di dialog pembayaran.
                    'payment_method' => $validated['payment_method'],
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'total' => $total,
                    'paid' => $paid,
                    'change' => max($paid - $total, 0),
                    'status' => 'completed',
                ]);

                // Kelompokkan item_discounts per index cart supaya mudah
                // dicocokkan ke urutan $validated['items'] (index array
                // yang dikirim ke PromotionService sama dengan urutan ini).
                $itemDiscountsByIndex = [];
                foreach ($promoResult->itemDiscounts as $discount) {
                    $itemDiscountsByIndex[$discount->cartItemIndex] = $discount;
                }

                foreach ($validated['items'] as $index => $item) {
                    $product = $productsById->get($item['product_id']) ?? Product::findOrFail($item['product_id']);
                    $discount = $itemDiscountsByIndex[$index] ?? null;

                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'price' => $item['price'],
                        'quantity' => $item['quantity'],
                        'subtotal' => $item['price'] * $item['quantity'],
                        'discount_amount' => $discount ? $discount->amount : 0,
                        'promotion_id' => $discount ? $discount->promotionId : null,
                        'is_bonus_item' => false,
                    ]);

                    if ($product->track_stock) {
                        $this->reduceStock($product, $item['unit'], $item['quantity'], $transaction, $request);
                    }
                }

                // Bonus item (BOGO) belum ada di $validated['items'] karena
                // kasir tidak "memilih" barang ini — dia muncul dari promo.
                // Tetap harus dibuat sebagai TransactionItem (supaya laporan
                // & histori struk benar) DAN ikut mengurangi stok (barangnya
                // tetap keluar dari gudang walau gratis/didiskon).
                foreach ($promoResult->bonusItems as $bonus) {
                    $bonusProduct = $productsById->get($bonus->productId)
                        ?? Product::find($bonus->productId);

                    if (! $bonusProduct) {
                        // Produk bonus sudah dihapus — lewati drpd transaksi gagal total.
                        continue;
                    }

                    $bonusLineTotal = $bonusProduct->price * $bonus->quantity;
                    $bonusDiscount = round($bonusLineTotal * $bonus->discountPercent / 100, 2);

                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $bonusProduct->id,
                        'product_name' => $bonusProduct->name,
                        'price' => $bonusProduct->price,
                        'quantity' => $bonus->quantity,
                        'subtotal' => $bonusLineTotal,
                        'discount_amount' => $bonusDiscount,
                        'promotion_id' => $bonus->promotionId,
                        'is_bonus_item' => true,
                    ]);

                    // ASUMSI: unit bonus item selalu "kecil" karena
                    // PromotionService/reward promo tidak membawa info unit
                    // besar/kecil. Sesuaikan kalau reward promo kamu bisa
                    // menentukan unit tertentu.
                    if ($bonusProduct->track_stock) {
                        $this->reduceStock($bonusProduct, StockMovement::UNIT_KECIL, $bonus->quantity, $transaction, $request);
                    }
                }

                return $transaction;
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $transaction->load('items');

        return response()->json(['data' => $transaction], 201);
    }

    /**
     * Bangun CartItem[] dari items request, pakai category_id dari tabel
     * products (bukan dari body — client tidak mengirim category_id sama
     * sekali) supaya promo per-kategori bisa dievaluasi dengan benar.
     *
     * @param  array<int, array{product_id:int, unit:string, quantity:int, price:float}>  $items
     * @param  \Illuminate\Support\Collection<int, Product>  $productsById
     * @return CartItem[]
     */
    protected function buildCartItems(array $items, \Illuminate\Support\Collection $productsById): array
    {
        return array_map(function (array $item) use ($productsById) {
            $product = $productsById->get($item['product_id']);

            return new CartItem(
                productId: (int) $item['product_id'],
                categoryId: $product?->category_id,
                price: (float) $item['price'],
                quantity: (int) $item['quantity'],
            );
        }, $items);
    }

    /**
     * Kurangi stok untuk satu item transaksi. Kalau jual satuan "kecil" dan
     * stok kecil tidak cukup, otomatis "bongkar" 1+ unit besar jadi kecil
     * (tercatat sebagai 2 StockMovement type=break_unit terpisah), PERSIS
     * seperti yang dijelaskan di komentar StockMovement::TYPE_BREAK_UNIT.
     *
     * Melempar RuntimeException kalau stok benar-benar tidak cukup —
     * ini akan me-rollback seluruh transaksi (item lain yang sudah
     * diproses ikut batal, DB::transaction yang menangani).
     */
    protected function reduceStock(Product $product, string $unit, int $quantity, Transaction $transaction, Request $request): void
    {
        $stock = Stock::firstOrCreate(
            ['product_id' => $product->id],
            ['quantity' => 0, 'qty_besar' => 0, 'qty_kecil' => 0]
        );

        if ($unit === StockMovement::UNIT_BESAR) {
            if ($stock->qty_besar < $quantity) {
                throw new \RuntimeException("Stok {$product->unit_besar} untuk {$product->name} tidak cukup.");
            }

            $stock->qty_besar -= $quantity;
            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'type' => StockMovement::TYPE_OUT,
                'unit' => StockMovement::UNIT_BESAR,
                'quantity' => -$quantity,
                'note' => 'Penjualan',
                'reference_id' => $transaction->id,
                'created_by' => $request->user()->id,
            ]);

            return;
        }

        // Unit kecil: cukup dari stok kecil langsung?
        if ($stock->qty_kecil >= $quantity) {
            $stock->qty_kecil -= $quantity;
            $stock->save();

            StockMovement::create([
                'product_id' => $product->id,
                'type' => StockMovement::TYPE_OUT,
                'unit' => StockMovement::UNIT_KECIL,
                'quantity' => -$quantity,
                'note' => 'Penjualan',
                'reference_id' => $transaction->id,
                'created_by' => $request->user()->id,
            ]);

            return;
        }

        // Stok kecil kurang -> coba auto-break dari unit besar (kalau produk multi-unit).
        if (! $product->hasMultiUnit()) {
            throw new \RuntimeException("Stok {$product->unit_kecil} untuk {$product->name} tidak cukup.");
        }

        $shortage = $quantity - $stock->qty_kecil;
        $boxesNeeded = (int) ceil($shortage / $product->conversion_qty);

        if ($stock->qty_besar < $boxesNeeded) {
            throw new \RuntimeException("Stok {$product->name} tidak cukup (termasuk setelah dibongkar dari {$product->unit_besar}).");
        }

        $stock->qty_besar -= $boxesNeeded;
        $stock->qty_kecil += $boxesNeeded * $product->conversion_qty;
        $stock->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_BREAK_UNIT,
            'unit' => StockMovement::UNIT_BESAR,
            'quantity' => -$boxesNeeded,
            'note' => "Bongkar {$boxesNeeded} {$product->unit_besar} jadi {$product->unit_kecil}",
            'reference_id' => $transaction->id,
            'created_by' => $request->user()->id,
        ]);

        StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_BREAK_UNIT,
            'unit' => StockMovement::UNIT_KECIL,
            'quantity' => $boxesNeeded * $product->conversion_qty,
            'note' => "Hasil bongkar {$boxesNeeded} {$product->unit_besar}",
            'reference_id' => $transaction->id,
            'created_by' => $request->user()->id,
        ]);

        // Sekarang stok kecil sudah cukup, kurangi sejumlah yang terjual.
        $stock->qty_kecil -= $quantity;
        $stock->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovement::TYPE_OUT,
            'unit' => StockMovement::UNIT_KECIL,
            'quantity' => -$quantity,
            'note' => 'Penjualan (setelah auto-break)',
            'reference_id' => $transaction->id,
            'created_by' => $request->user()->id,
        ]);
    }

    protected function generateTransactionNumber(): string
    {
        do {
            $number = 'TRX-' . now()->format('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        } while (Transaction::where('transaction_number', $number)->exists());

        return $number;
    }
}
