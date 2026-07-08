<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Jual produk sejumlah $qty dalam satuan $unit ('besar' atau 'kecil').
     *
     * Untuk produk multi-satuan, kalau stok satuan_kecil tidak cukup
     * saat menjual satuan_kecil, otomatis "bongkar" sejumlah unit_besar
     * yang diperlukan (dibulatkan ke atas) menjadi unit_kecil, tercatat
     * sebagai StockMovement terpisah, SEBELUM memotong stok untuk
     * penjualan itu sendiri.
     *
     * Dibungkus transaction + lockForUpdate supaya aman dari race
     * condition kalau ada 2 kasir menjual produk yang sama bersamaan.
     *
     * @throws InsufficientStockException kalau stok (termasuk setelah
     *         bongkar box yang tersedia) tidak cukup untuk penjualan ini.
     */
    public function jual(
        Product $product,
        string $unit,
        int $qty,
        ?int $referenceId = null,
        ?int $userId = null,
    ): void {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Jumlah penjualan harus lebih dari 0.');
        }

        if (! $product->track_stock) {
            // Produk yang tidak melacak stok (mis. jasa) tidak perlu
            // ada pengurangan sama sekali.
            return;
        }

        DB::transaction(function () use ($product, $unit, $qty, $referenceId, $userId) {
            /** @var Stock $stock */
            $stock = Stock::where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($unit === StockMovement::UNIT_BESAR) {
                $this->jualUnitBesar($product, $stock, $qty, $referenceId, $userId);
                return;
            }

            $this->jualUnitKecilDenganAutoBreak($product, $stock, $qty, $referenceId, $userId);
        });
    }

    private function jualUnitBesar(
        Product $product,
        Stock $stock,
        int $qty,
        ?int $referenceId,
        ?int $userId,
    ): void {
        if (! $product->hasMultiUnit()) {
            throw new InsufficientStockException(
                "{$product->name} tidak punya satuan besar yang bisa dijual."
            );
        }

        if ($stock->qty_besar < $qty) {
            throw new InsufficientStockException(
                "Stok {$product->unit_besar} {$product->name} tidak cukup. "
                . "Sisa: {$stock->qty_besar}, dibutuhkan: {$qty}."
            );
        }

        $stock->qty_besar -= $qty;
        $stock->save();

        $this->catatMovement(
            $product, StockMovement::TYPE_OUT, StockMovement::UNIT_BESAR,
            -$qty, "Penjualan {$qty} {$product->unit_besar}", $referenceId, $userId,
        );
    }

    private function jualUnitKecilDenganAutoBreak(
        Product $product,
        Stock $stock,
        int $qty,
        ?int $referenceId,
        ?int $userId,
    ): void {
        $unitKecilLabel = $product->unit_kecil ?: 'satuan';

        if ($stock->qty_kecil < $qty && $product->hasMultiUnit()) {
            $kurang = $qty - $stock->qty_kecil;
            $boxDiperlukan = (int) ceil($kurang / $product->conversion_qty);

            if ($stock->qty_besar < $boxDiperlukan) {
                throw new InsufficientStockException(
                    "Stok {$unitKecilLabel} {$product->name} tidak cukup, dan stok "
                    . "{$product->unit_besar} juga tidak cukup untuk dibongkar. "
                    . "Sisa {$unitKecilLabel}: {$stock->qty_kecil}, "
                    . "sisa {$product->unit_besar}: {$stock->qty_besar}."
                );
            }

            // Bongkar $boxDiperlukan box menjadi unit_kecil.
            $hasilBongkar = $boxDiperlukan * $product->conversion_qty;
            $stock->qty_besar -= $boxDiperlukan;
            $stock->qty_kecil += $hasilBongkar;

            $this->catatMovement(
                $product, StockMovement::TYPE_BREAK_UNIT, StockMovement::UNIT_BESAR,
                -$boxDiperlukan,
                "Bongkar {$boxDiperlukan} {$product->unit_besar} karena stok {$unitKecilLabel} habis",
                $referenceId, $userId,
            );
            $this->catatMovement(
                $product, StockMovement::TYPE_BREAK_UNIT, StockMovement::UNIT_KECIL,
                $hasilBongkar,
                "Hasil bongkar {$boxDiperlukan} {$product->unit_besar} menjadi {$unitKecilLabel}",
                $referenceId, $userId,
            );
        }

        if ($stock->qty_kecil < $qty) {
            throw new InsufficientStockException(
                "Stok {$unitKecilLabel} {$product->name} tidak cukup. "
                . "Sisa: {$stock->qty_kecil}, dibutuhkan: {$qty}."
            );
        }

        $stock->qty_kecil -= $qty;
        $stock->save();

        $this->catatMovement(
            $product, StockMovement::TYPE_OUT, StockMovement::UNIT_KECIL,
            -$qty, "Penjualan {$qty} {$unitKecilLabel}", $referenceId, $userId,
        );
    }

    private function catatMovement(
        Product $product,
        string $type,
        string $unit,
        int $quantity,
        string $note,
        ?int $referenceId,
        ?int $userId,
    ): void {
        StockMovement::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'type' => $type,
            'unit' => $unit,
            'quantity' => $quantity,
            'note' => $note,
            'reference_id' => $referenceId,
            'created_by' => $userId,
        ]);
    }
}
