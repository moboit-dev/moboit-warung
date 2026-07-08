<?php

namespace App\Services\Promotion;

use App\Models\Promotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Mengevaluasi promo mana yang berlaku untuk satu keranjang transaksi, lalu
 * menghitung diskon per item, diskon cart, dan hadiah bonus (BOGO).
 *
 * ATURAN UTAMA (mengikuti keputusan desain di dokumen teknis 4.12):
 * 1. Tidak ada stacking otomatis. Setiap promo punya `priority` manual.
 * 2. Untuk item yang sama, hanya 1 promo ITEM yang menang (priority tertinggi yang cocok).
 * 3. Untuk level keranjang, hanya 1 promo CART yang menang.
 * 4. Promo item & promo cart BISA jalan bersamaan (beda level, bukan stacking bermasalah).
 * 5. BOGO dievaluasi terpisah dari diskon nilai (percentage/fixed).
 *
 * ASUMSI TAMBAHAN YANG DIDOKUMENTASIKAN DI SINI (penting dibaca sebelum dipakai produksi):
 * - Kalau ada 2+ promo item dengan priority SAMA, pemenang diambil berdasarkan promotion_id
 *   terkecil (tie-break stabil, deterministik, bukan berdasar urutan array).
 * - Diskon item dihitung dari harga satuan (unit price), lalu dikalikan quantity baris tsb.
 *   Promo TIDAK dipecah per unit (mis. quantity 3 tapi promo cuma untuk 1 unit) — versi ini
 *   menerapkan ke seluruh quantity pada baris yang match.
 * - Diskon cart dihitung dari subtotal SETELAH diskon item diterapkan (bukan subtotal kotor),
 *   supaya tidak dobel-diskon di atas nilai yang sudah dipotong promo item.
 * - Untuk BOGO: SEMUA promotion_conditions milik satu promotion harus terpenuhi (AND).
 *   Promo hanya terpicu SEKALI per transaksi meski quantity di keranjang lebih dari
 *   min_quantity berkali-kali lipat (mis. beli 6 padahal syarat cuma 2 -> tetap 1x hadiah).
 *   Jika butuh logika "kelipatan" (mis. tiap 2 dapat 1, beli 6 dapat 3), itu adalah perubahan
 *   behavior yang perlu keputusan produk terpisah — belum diimplementasikan di sini.
 * - Kalau ada 2+ promo BOGO yang conditions-nya sama-sama terpenuhi dan berebut produk yang
 *   sama, dipakai aturan priority yang sama seperti promo item (tertinggi menang, hanya 1 yang
 *   jalan untuk produk syarat yang sama).
 */
class PromotionService
{
    /**
     * @param  CartItem[]  $cartItems
     */
    public function evaluate(int $tenantId, array $cartItems, ?Carbon $at = null): PromotionResult
    {
        $at ??= Carbon::now();

        $promotions = $this->loadActivePromotions($tenantId, $at);

        $itemPromotions = $promotions->filter(fn (Promotion $p) => $p->isItemLevel());
        $cartPromotions = $promotions->filter(fn (Promotion $p) => $p->isCartLevel());
        $bogoPromotions = $promotions->filter(fn (Promotion $p) => $p->isBogo());

        $itemDiscounts = $this->evaluateItemDiscounts($cartItems, $itemPromotions);

        $subtotal = array_sum(array_map(fn (CartItem $item) => $item->lineTotal(), $cartItems));
        $subtotalAfterItemDiscounts = $subtotal - array_sum(
            array_map(fn (AppliedItemDiscount $d) => $d->amount, $itemDiscounts)
        );

        $cartDiscount = $this->evaluateCartDiscount($subtotalAfterItemDiscounts, $cartPromotions);
        $bonusItems = $this->evaluateBogoRewards($cartItems, $bogoPromotions);

        return new PromotionResult(
            itemDiscounts: $itemDiscounts,
            cartDiscount: $cartDiscount,
            bonusItems: $bonusItems,
            subtotal: $subtotal,
        );
    }

    /** @return Collection<int, Promotion> */
    protected function loadActivePromotions(int $tenantId, Carbon $at): Collection
    {
        return Promotion::query()
            ->forTenant($tenantId)
            ->activeAt($at)
            ->with(['targets', 'conditions', 'rewards'])
            ->orderByDesc('priority')
            ->orderBy('id') // tie-break deterministik
            ->get();
    }

    /**
     * @param  CartItem[]  $cartItems
     * @param  Collection<int, Promotion>  $itemPromotions
     * @return AppliedItemDiscount[]
     */
    protected function evaluateItemDiscounts(array $cartItems, Collection $itemPromotions): array
    {
        $results = [];

        foreach ($cartItems as $index => $item) {
            $matching = $itemPromotions->filter(function (Promotion $promo) use ($item) {
                return $promo->targets->contains(
                    fn (\App\Models\PromotionTarget $t) => ($t->target_type === 'product' && (int) $t->target_id === $item->productId)
                        || ($t->target_type === 'category' && $item->categoryId !== null && (int) $t->target_id === $item->categoryId)
                );
            });

            if ($matching->isEmpty()) {
                continue;
            }

            // Sudah terurut priority desc, id asc dari loadActivePromotions().
            $winner = $matching->first();

            $amount = $winner->isPercentage()
                ? $item->lineTotal() * ((float) $winner->value / 100)
                : min((float) $winner->value * $item->quantity, $item->lineTotal()); // fixed: jangan sampai diskon > harga

            $results[] = new AppliedItemDiscount(
                cartItemIndex: $index,
                promotionId: $winner->id,
                promotionName: $winner->name,
                amount: round($amount, 2),
            );
        }

        return $results;
    }

    /** @param  Collection<int, Promotion>  $cartPromotions */
    protected function evaluateCartDiscount(float $subtotalAfterItemDiscounts, Collection $cartPromotions): ?AppliedCartDiscount
    {
        if ($cartPromotions->isEmpty() || $subtotalAfterItemDiscounts <= 0) {
            return null;
        }

        // Sudah terurut priority desc, id asc.
        $winner = $cartPromotions->first();

        $amount = $winner->isPercentage()
            ? $subtotalAfterItemDiscounts * ((float) $winner->value / 100)
            : min((float) $winner->value, $subtotalAfterItemDiscounts);

        return new AppliedCartDiscount(
            promotionId: $winner->id,
            promotionName: $winner->name,
            amount: round($amount, 2),
        );
    }

    /**
     * @param  CartItem[]  $cartItems
     * @param  Collection<int, Promotion>  $bogoPromotions
     * @return BonusItem[]
     */
    protected function evaluateBogoRewards(array $cartItems, Collection $bogoPromotions): array
    {
        $qtyByProduct = [];
        foreach ($cartItems as $item) {
            $qtyByProduct[$item->productId] = ($qtyByProduct[$item->productId] ?? 0) + $item->quantity;
        }

        $bonusItems = [];
        $claimedTriggerProducts = []; // product_id syarat yang sudah "dipakai" oleh promo bogo pemenang

        foreach ($bogoPromotions as $promo) {
            if ($promo->conditions->isEmpty()) {
                continue;
            }

            // Semua condition harus terpenuhi (AND).
            $allConditionsMet = $promo->conditions->every(
                fn (\App\Models\PromotionCondition $c) => ($qtyByProduct[$c->product_id] ?? 0) >= $c->min_quantity
            );

            if (! $allConditionsMet) {
                continue;
            }

            // Cegah produk syarat yang sama dipakai lebih dari 1 promo bogo (promo dengan
            // priority lebih tinggi sudah dievaluasi lebih dulu karena koleksi sudah terurut).
            $triggerProductIds = $promo->conditions->pluck('product_id')->all();
            if (array_intersect($triggerProductIds, $claimedTriggerProducts) !== []) {
                continue;
            }

            foreach ($promo->rewards as $reward) {
                // product_id null -> hadiahnya produk yang sama dengan syarat pertama
                $rewardProductId = $reward->product_id ?? $promo->conditions->first()->product_id;

                $bonusItems[] = new BonusItem(
                    productId: $rewardProductId,
                    quantity: $reward->quantity,
                    discountPercent: (float) $reward->discount_percent,
                    promotionId: $promo->id,
                    promotionName: $promo->name,
                );
            }

            array_push($claimedTriggerProducts, ...$triggerProductIds);
        }

        return $bonusItems;
    }
}
