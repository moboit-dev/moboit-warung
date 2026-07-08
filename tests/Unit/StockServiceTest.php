<?php

namespace Tests\Unit;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forceCreate([
            'id' => 1,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        // Product, Stock, dan StockMovement pakai trait BelongsToTenant yang
        // menambahkan global scope: tanpa tenant context aktif, SEMUA query
        // SELECT diblokir (whereRaw('1=0')) demi keamanan multi-tenant.
        // Karena test ini tidak ada user yang login, kita bind context-nya
        // secara eksplisit lewat mekanisme yang sudah disediakan trait-nya.
        app()->instance('current_tenant_id', 1);
    }

    protected function makeMultiUnitProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => 1,
            'name' => 'Indomie Goreng',
            'sku' => 'SKU-INDOMIE',
            'cost_price' => 2500,
            'price' => 3000,
            'price_besar' => 65000,
            'type' => 'barang',
            'track_stock' => true,
            'unit_besar' => 'Box',
            'unit_kecil' => 'Sachet',
            'conversion_qty' => 24,
        ], $attrs));
    }

    protected function makeSingleUnitProduct(array $attrs = []): Product
    {
        return Product::create(array_merge([
            'tenant_id' => 1,
            'name' => 'Air Mineral 600ml',
            'sku' => 'SKU-AIR',
            'cost_price' => 2000,
            'price' => 3000,
            'type' => 'barang',
            'track_stock' => true,
        ], $attrs));
    }

    protected function makeStock(Product $product, int $qtyBesar, int $qtyKecil): Stock
    {
        return Stock::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'qty_besar' => $qtyBesar,
            'qty_kecil' => $qtyKecil,
        ]);
    }

    #[Test]
    public function jual_unit_besar_mengurangi_qty_besar_dan_mencatat_movement(): void
    {
        $product = $this->makeMultiUnitProduct();
        $stock = $this->makeStock($product, qtyBesar: 10, qtyKecil: 5);

        (new StockService())->jual($product, StockMovement::UNIT_BESAR, 3);

        $stock->refresh();
        $this->assertSame(7, $stock->qty_besar);
        $this->assertSame(5, $stock->qty_kecil); // qty_kecil tidak boleh ikut berubah

        $movements = StockMovement::where('product_id', $product->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovement::TYPE_OUT, $movements[0]->type);
        $this->assertSame(StockMovement::UNIT_BESAR, $movements[0]->unit);
        $this->assertSame(-3, $movements[0]->quantity);
    }

    #[Test]
    public function jual_unit_besar_gagal_kalau_stok_box_tidak_cukup(): void
    {
        $product = $this->makeMultiUnitProduct();
        $stock = $this->makeStock($product, qtyBesar: 2, qtyKecil: 0);

        try {
            (new StockService())->jual($product, StockMovement::UNIT_BESAR, 5);
            $this->fail('Seharusnya melempar InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        // Pastikan tidak ada perubahan stok maupun movement kalau gagal
        $stock->refresh();
        $this->assertSame(2, $stock->qty_besar);
        $this->assertCount(0, StockMovement::where('product_id', $product->id)->get());
    }

    #[Test]
    public function jual_unit_besar_gagal_untuk_produk_yang_tidak_punya_satuan_besar(): void
    {
        $product = $this->makeSingleUnitProduct();
        $this->makeStock($product, qtyBesar: 0, qtyKecil: 50);

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('tidak punya satuan besar');

        (new StockService())->jual($product, StockMovement::UNIT_BESAR, 1);
    }

    #[Test]
    public function jual_unit_kecil_langsung_mengurangi_stok_tanpa_auto_break_kalau_masih_cukup(): void
    {
        $product = $this->makeMultiUnitProduct();
        $stock = $this->makeStock($product, qtyBesar: 5, qtyKecil: 10);

        (new StockService())->jual($product, StockMovement::UNIT_KECIL, 4);

        $stock->refresh();
        $this->assertSame(5, $stock->qty_besar); // box tidak boleh ikut kebongkar
        $this->assertSame(6, $stock->qty_kecil);

        $movements = StockMovement::where('product_id', $product->id)->get();
        $this->assertCount(1, $movements); // tidak ada movement break_unit
        $this->assertSame(StockMovement::TYPE_OUT, $movements[0]->type);
        $this->assertSame(StockMovement::UNIT_KECIL, $movements[0]->unit);
        $this->assertSame(-4, $movements[0]->quantity);
    }

    #[Test]
    public function jual_unit_kecil_auto_break_box_ketika_stok_sachet_tidak_cukup(): void
    {
        $product = $this->makeMultiUnitProduct(['conversion_qty' => 24]);
        // Sachet cuma sisa 2, mau jual 5 -> kurang 3 -> perlu bongkar 1 box (ceil(3/24)=1)
        $stock = $this->makeStock($product, qtyBesar: 3, qtyKecil: 2);

        (new StockService())->jual($product, StockMovement::UNIT_KECIL, 5);

        $stock->refresh();
        // 1 box dibongkar: qty_besar 3 -> 2, qty_kecil 2 + 24 = 26, lalu dipakai jual 5 -> 21
        $this->assertSame(2, $stock->qty_besar);
        $this->assertSame(21, $stock->qty_kecil);

        $movements = StockMovement::where('product_id', $product->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $movements);

        // 1. Movement bongkar box (berkurang)
        $this->assertSame(StockMovement::TYPE_BREAK_UNIT, $movements[0]->type);
        $this->assertSame(StockMovement::UNIT_BESAR, $movements[0]->unit);
        $this->assertSame(-1, $movements[0]->quantity);

        // 2. Movement hasil bongkar jadi sachet (bertambah)
        $this->assertSame(StockMovement::TYPE_BREAK_UNIT, $movements[1]->type);
        $this->assertSame(StockMovement::UNIT_KECIL, $movements[1]->unit);
        $this->assertSame(24, $movements[1]->quantity);

        // 3. Movement penjualan itu sendiri
        $this->assertSame(StockMovement::TYPE_OUT, $movements[2]->type);
        $this->assertSame(StockMovement::UNIT_KECIL, $movements[2]->unit);
        $this->assertSame(-5, $movements[2]->quantity);
    }

    #[Test]
    public function jual_unit_kecil_gagal_kalau_sachet_dan_box_sama_sama_tidak_cukup(): void
    {
        $product = $this->makeMultiUnitProduct(['conversion_qty' => 24]);
        // Sachet sisa 2, mau jual 5 -> kurang 3 -> perlu 1 box, tapi box cuma 0
        $stock = $this->makeStock($product, qtyBesar: 0, qtyKecil: 2);

        try {
            (new StockService())->jual($product, StockMovement::UNIT_KECIL, 5);
            $this->fail('Seharusnya melempar InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        // Pastikan tidak ada perubahan stok maupun movement kalau gagal total
        $stock->refresh();
        $this->assertSame(0, $stock->qty_besar);
        $this->assertSame(2, $stock->qty_kecil);
        $this->assertCount(0, StockMovement::where('product_id', $product->id)->get());
    }

    #[Test]
    public function jual_unit_kecil_untuk_produk_single_unit_gagal_langsung_tanpa_auto_break(): void
    {
        $product = $this->makeSingleUnitProduct();
        $stock = $this->makeStock($product, qtyBesar: 0, qtyKecil: 3);

        $this->expectException(InsufficientStockException::class);

        // Produk single-unit tidak punya konsep box, jadi walau qty_besar
        // ada isinya (harusnya selalu 0), tidak boleh dicoba dibongkar.
        (new StockService())->jual($product, StockMovement::UNIT_KECIL, 5);
    }

    #[Test]
    public function produk_yang_tidak_melacak_stok_tidak_mengurangi_apapun(): void
    {
        $product = $this->makeMultiUnitProduct(['track_stock' => false]);
        // Sengaja tidak buat record Stock sama sekali -> kalau service
        // salah-salah query stok, ini akan meledak duluan.

        (new StockService())->jual($product, StockMovement::UNIT_KECIL, 100);

        $this->assertCount(0, StockMovement::where('product_id', $product->id)->get());
    }

    #[Test]
    public function jual_dengan_qty_nol_atau_negatif_dilempar_exception(): void
    {
        $product = $this->makeMultiUnitProduct();
        $this->makeStock($product, qtyBesar: 10, qtyKecil: 10);

        $this->expectException(\InvalidArgumentException::class);

        (new StockService())->jual($product, StockMovement::UNIT_KECIL, 0);
    }

    #[Test]
    public function jual_box_dan_sachet_dalam_satu_nota_tercatat_dengan_reference_yang_sama(): void
    {
        $product = $this->makeMultiUnitProduct(['conversion_qty' => 24]);
        // Skenario: 1 nota, pelanggan beli 2 Box SEKALIGUS 15 Sachet lepas
        // dari produk yang sama. Di kasir ini berarti StockService::jual()
        // dipanggil 2x berurutan (baris 1 = besar, baris 2 = kecil),
        // dengan reference_id yang sama (nomor nota yang sama).
        $stock = $this->makeStock($product, qtyBesar: 5, qtyKecil: 10);
        $notaId = 999;

        $service = new StockService();

        // Baris 1 nota: jual 2 Box
        $service->jual($product, StockMovement::UNIT_BESAR, 2, referenceId: $notaId);

        // Baris 2 nota: jual 15 Sachet -> stok sachet cuma sisa 10,
        // kurang 5 -> perlu bongkar 1 box lagi (ceil(5/24) = 1)
        $service->jual($product, StockMovement::UNIT_KECIL, 15, referenceId: $notaId);

        $stock->refresh();

        // Box: 5 - 2 (baris 1) - 1 (bongkar untuk baris 2) = 2
        $this->assertSame(2, $stock->qty_besar);
        // Sachet: 10 + 24 (hasil bongkar) - 15 (baris 2) = 19
        $this->assertSame(19, $stock->qty_kecil);

        // Total stok kalau dikonversi semua ke sachet harus tetap konsisten
        // dengan hitungan manual: (2 box * 24) + 19 sachet = 67
        $this->assertSame(67, $stock->totalDalamSatuanKecil());

        $movements = StockMovement::where('product_id', $product->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $movements);

        // Semua movement dalam nota yang sama harus punya reference_id yang sama
        foreach ($movements as $movement) {
            $this->assertSame($notaId, $movement->reference_id);
        }

        // 1. Penjualan baris 1: 2 Box
        $this->assertSame(StockMovement::TYPE_OUT, $movements[0]->type);
        $this->assertSame(StockMovement::UNIT_BESAR, $movements[0]->unit);
        $this->assertSame(-2, $movements[0]->quantity);

        // 2. Bongkar 1 box untuk memenuhi baris 2
        $this->assertSame(StockMovement::TYPE_BREAK_UNIT, $movements[1]->type);
        $this->assertSame(StockMovement::UNIT_BESAR, $movements[1]->unit);
        $this->assertSame(-1, $movements[1]->quantity);

        // 3. Hasil bongkar jadi sachet
        $this->assertSame(StockMovement::TYPE_BREAK_UNIT, $movements[2]->type);
        $this->assertSame(StockMovement::UNIT_KECIL, $movements[2]->unit);
        $this->assertSame(24, $movements[2]->quantity);

        // 4. Penjualan baris 2: 15 Sachet
        $this->assertSame(StockMovement::TYPE_OUT, $movements[3]->type);
        $this->assertSame(StockMovement::UNIT_KECIL, $movements[3]->unit);
        $this->assertSame(-15, $movements[3]->quantity);
    }

    #[Test]
    public function jual_box_dan_sachet_berurutan_tetap_gagal_bersih_kalau_baris_kedua_kekurangan_stok(): void
    {
        $product = $this->makeMultiUnitProduct(['conversion_qty' => 24]);
        // Box cuma 1, sachet cuma 2. Baris 1 (jual 1 box) akan berhasil dan
        // menghabiskan box, sehingga baris 2 (jual 30 sachet) tidak akan
        // punya box tersisa untuk dibongkar -> harus gagal, TAPI hasil
        // baris 1 yang sudah sukses harus tetap tersimpan (transaction
        // per baris terpisah, bukan 1 transaction gabungan).
        $stock = $this->makeStock($product, qtyBesar: 1, qtyKecil: 2);
        $notaId = 1000;

        $service = new StockService();

        $service->jual($product, StockMovement::UNIT_BESAR, 1, referenceId: $notaId);

        try {
            $service->jual($product, StockMovement::UNIT_KECIL, 30, referenceId: $notaId);
            $this->fail('Seharusnya melempar InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $stock->refresh();
        // Baris 1 tetap tercatat (box habis jadi 0), baris 2 gagal total
        // jadi sachet tidak berubah sama sekali (masih 2, bukan 32/17/dll).
        $this->assertSame(0, $stock->qty_besar);
        $this->assertSame(2, $stock->qty_kecil);

        $movements = StockMovement::where('product_id', $product->id)->get();
        $this->assertCount(1, $movements); // cuma movement dari baris 1 yang berhasil
        $this->assertSame(StockMovement::UNIT_BESAR, $movements[0]->unit);
    }
}
