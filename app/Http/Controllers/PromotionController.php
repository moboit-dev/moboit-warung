<?php

namespace App\Http\Controllers;

use App\Http\Requests\EvaluateCartRequest;
use App\Http\Requests\StorePromotionRequest;
use App\Http\Requests\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use App\Services\Promotion\CartItem;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromotionController extends Controller
{
    public function __construct(protected PromotionService $promotionService)
    {
    }

    /**
     * GET /api/promotions
     * List promo milik tenant yang sedang login, terbaru dulu.
     */
    public function index(Request $request): JsonResponse
    {
        $promotions = Promotion::query()
            ->forTenant($this->tenantId($request))
            ->with(['targets', 'conditions', 'rewards'])
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => PromotionResource::collection($promotions->items()),
            'meta' => [
                'current_page' => $promotions->currentPage(),
                'last_page' => $promotions->lastPage(),
                'total' => $promotions->total(),
            ],
        ]);
    }

    /**
     * GET /api/promotions/{promotion}
     */
    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeTenant($request, $promotion);

        $promotion->load(['targets', 'conditions', 'rewards']);

        return response()->json(['data' => new PromotionResource($promotion)]);
    }

    /**
     * POST /api/promotions
     * Body: { name, type, target_type?, value?, start_date, end_date, priority?, is_active?,
     *         targets?: [{target_type, target_id}],
     *         conditions?: [{product_id, min_quantity}],
     *         rewards?: [{product_id?, quantity, discount_percent}] }
     */
    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = DB::transaction(function () use ($request) {
            $promotion = Promotion::create([
                'tenant_id' => $this->tenantId($request),
                'name' => $request->input('name'),
                'type' => $request->input('type'),
                'target_type' => $request->input('target_type'),
                'value' => $request->input('value'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'priority' => $request->input('priority', 0),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->syncChildren($promotion, $request);

            return $promotion;
        });

        $promotion->load(['targets', 'conditions', 'rewards']);

        return response()->json(['data' => new PromotionResource($promotion)], 201);
    }

    /**
     * PUT/PATCH /api/promotions/{promotion}
     * Full replace: seluruh targets/conditions/rewards lama dihapus, diganti yang baru dikirim.
     */
    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeTenant($request, $promotion);

        DB::transaction(function () use ($request, $promotion) {
            $promotion->update([
                'name' => $request->input('name'),
                'type' => $request->input('type'),
                'target_type' => $request->input('target_type'),
                'value' => $request->input('value'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'priority' => $request->input('priority', $promotion->priority),
                'is_active' => $request->boolean('is_active', $promotion->is_active),
            ]);

            $promotion->targets()->delete();
            $promotion->conditions()->delete();
            $promotion->rewards()->delete();

            $this->syncChildren($promotion, $request);
        });

        $promotion->load(['targets', 'conditions', 'rewards']);

        return response()->json(['data' => new PromotionResource($promotion)]);
    }

    /**
     * DELETE /api/promotions/{promotion}
     * Soft delete saja (histori promo dipertahankan untuk laporan/analitik).
     */
    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $this->authorizeTenant($request, $promotion);

        $promotion->delete();

        return response()->json(null, 204);
    }

    /**
     * POST /api/promotions/evaluate-cart
     * Body: { items: [{product_id, category_id?, price, quantity}] }
     *
     * Dipanggil dari alur kasir/checkout SEBELUM transaksi disimpan, untuk mendapat preview
     * diskon (item, cart, bonus BOGO) sesuai isi keranjang saat itu.
     */
    public function evaluateCart(EvaluateCartRequest $request): JsonResponse
    {
        $cartItems = array_map(
            fn (array $item) => new CartItem(
                productId: (int) $item['product_id'],
                categoryId: isset($item['category_id']) ? (int) $item['category_id'] : null,
                price: (float) $item['price'],
                quantity: (int) $item['quantity'],
            ),
            $request->input('items')
        );

        $result = $this->promotionService->evaluate($this->tenantId($request), $cartItems);

        return response()->json([
            'data' => [
                'subtotal' => $result->subtotal,
                'total_item_discount' => $result->totalItemDiscount(),
                'total_cart_discount' => $result->totalCartDiscount(),
                'grand_total' => $result->grandTotal(),
                'item_discounts' => array_map(fn ($d) => [
                    'cart_item_index' => $d->cartItemIndex,
                    'promotion_id' => $d->promotionId,
                    'promotion_name' => $d->promotionName,
                    'amount' => $d->amount,
                ], $result->itemDiscounts),
                'cart_discount' => $result->cartDiscount ? [
                    'promotion_id' => $result->cartDiscount->promotionId,
                    'promotion_name' => $result->cartDiscount->promotionName,
                    'amount' => $result->cartDiscount->amount,
                ] : null,
                'bonus_items' => array_map(fn ($b) => [
                    'product_id' => $b->productId,
                    'quantity' => $b->quantity,
                    'discount_percent' => $b->discountPercent,
                    'promotion_id' => $b->promotionId,
                    'promotion_name' => $b->promotionName,
                ], $result->bonusItems),
            ],
        ]);
    }

    /**
     * ASUMSI: tenant_id diambil dari user yang sedang login (kolom users.tenant_id),
     * BUKAN dari request body — supaya satu tenant tidak bisa membaca/mengubah promo
     * tenant lain hanya dengan mengganti tenant_id di payload.
     * Sesuaikan bagian ini kalau struktur auth/tenant di project kamu berbeda.
     */
    protected function tenantId(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    protected function authorizeTenant(Request $request, Promotion $promotion): void
    {
        abort_if($promotion->tenant_id !== $this->tenantId($request), 404);
    }

    protected function syncChildren(Promotion $promotion, Request $request): void
    {
        foreach ($request->input('targets', []) as $target) {
            $promotion->targets()->create($target);
        }

        foreach ($request->input('conditions', []) as $condition) {
            $promotion->conditions()->create($condition);
        }

        foreach ($request->input('rewards', []) as $reward) {
            $promotion->rewards()->create($reward);
        }
    }
}
