<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Services\Promotion\CartItem;
use App\Services\Promotion\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Promotion butuh tenant_id yang valid (foreign key),
        // jadi kita buat dulu 1 tenant dengan id = 1 sebelum tiap test jalan.
        Tenant::create([
            'id' => 1,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        // promotion_targets & promotion_conditions punya FK ke products,
        // jadi produk yang dipakai di test (id 100 & 200) harus benar-benar ada.
        // forceCreate dipakai karena kolom "id" sengaja tidak ada di $fillable
        // Product (mass-assignment protection). create() biasa akan diam-diam
        // mengabaikan id yang kita set manual di sini.
        Product::forceCreate([
            'id' => 100,
            'tenant_id' => 1,
            'name' => 'Produk Uji 100',
            'sku' => 'SKU-100',
            'cost_price' => 5000,
            'price' => 10000,
            'type' => 'barang',
            'track_stock' => false,
        ]);

        Product::forceCreate([
            'id' => 200,
            'tenant_id' => 1,
            'name' => 'Roti Uji 200',
            'sku' => 'SKU-200',
            'cost_price' => 8000,
            'price' => 15000,
            'type' => 'barang',
            'track_stock' => false,
        ]);
    }

    protected function makePromotion(array $attrs): Promotion
    {
        return Promotion::create(array_merge([
            'tenant_id' => 1,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addDay(),
            'priority' => 0,
            'is_active' => true,
        ], $attrs));
    }

    #[Test]
    public function only_the_highest_priority_item_promotion_wins_for_the_same_product(): void
    {
        $productId = 100;

        $low = $this->makePromotion([
            'name' => 'Diskon 10%',
            'type' => 'percentage',
            'target_type' => 'product',
            'value' => 10,
            'priority' => 1,
        ]);
        $low->targets()->create(['target_type' => 'product', 'target_id' => $productId]);

        $high = $this->makePromotion([
            'name' => 'Diskon 20%',
            'type' => 'percentage',
            'target_type' => 'product',
            'value' => 20,
            'priority' => 5,
        ]);
        $high->targets()->create(['target_type' => 'product', 'target_id' => $productId]);

        $cart = [new CartItem(productId: $productId, categoryId: null, price: 10000, quantity: 2)];

        $result = (new PromotionService())->evaluate(tenantId: 1, cartItems: $cart);

        $this->assertCount(1, $result->itemDiscounts);
        $this->assertSame($high->id, $result->itemDiscounts[0]->promotionId);
        $this->assertEqualsWithDelta(4000.0, $result->itemDiscounts[0]->amount, 0.01); // 20% * 20000
    }

    #[Test]
    public function item_and_cart_level_promotions_apply_simultaneously(): void
    {
        $productId = 100;

        $itemPromo = $this->makePromotion([
            'name' => 'Diskon Produk',
            'type' => 'percentage',
            'target_type' => 'product',
            'value' => 10,
        ]);
        $itemPromo->targets()->create(['target_type' => 'product', 'target_id' => $productId]);

        $cartPromo = $this->makePromotion([
            'name' => 'Diskon Belanja',
            'type' => 'fixed',
            'target_type' => 'cart',
            'value' => 5000,
        ]);

        $cart = [new CartItem(productId: $productId, categoryId: null, price: 100000, quantity: 1)];

        $result = (new PromotionService())->evaluate(tenantId: 1, cartItems: $cart);

        $this->assertCount(1, $result->itemDiscounts);
        $this->assertNotNull($result->cartDiscount);
        $this->assertSame($cartPromo->id, $result->cartDiscount->promotionId);
        // subtotal 100000 - diskon item 10000 = 90000, lalu -5000 cart = 85000
        $this->assertEqualsWithDelta(85000.0, $result->grandTotal(), 0.01);
    }

    #[Test]
    public function bogo_reward_is_granted_once_when_condition_is_met(): void
    {
        $breadId = 200;

        $bogo = $this->makePromotion([
            'name' => 'Buy 1 Get 1 Roti',
            'type' => 'bogo',
        ]);
        $bogo->conditions()->create(['product_id' => $breadId, 'min_quantity' => 1]);
        $bogo->rewards()->create(['product_id' => null, 'quantity' => 1, 'discount_percent' => 100]);

        $cart = [new CartItem(productId: $breadId, categoryId: null, price: 15000, quantity: 3)];

        $result = (new PromotionService())->evaluate(tenantId: 1, cartItems: $cart);

        $this->assertCount(1, $result->bonusItems); // hanya 1x, bukan 3x meski quantity 3
        $this->assertSame($breadId, $result->bonusItems[0]->productId);
        $this->assertEqualsWithDelta(100.0, $result->bonusItems[0]->discountPercent, 0.01);
    }
}
