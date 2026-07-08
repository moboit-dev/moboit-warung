<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // percentage / fixed  -> diskon nilai (pakai promotion_targets)
            // bogo                -> bundling (pakai promotion_conditions + promotion_rewards)
            $table->enum('type', ['percentage', 'fixed', 'bogo']);

            // Hanya relevan untuk type percentage/fixed.
            // - product / category -> level "item", butuh baris di promotion_targets
            // - cart               -> level "keranjang" (total transaksi), tidak butuh target
            // Null untuk type bogo, karena bogo pakai conditions/rewards, bukan target.
            $table->enum('target_type', ['product', 'category', 'cart'])->nullable();

            // Nilai diskon. Untuk type=percentage: persen (0-100). Untuk type=fixed: nominal rupiah.
            // Null untuk type=bogo (nilai hadiah bogo ada di promotion_rewards.discount_percent).
            $table->decimal('value', 12, 2)->nullable();

            $table->dateTime('start_date');
            $table->dateTime('end_date');

            // Priority manual, diatur admin. Angka LEBIH BESAR = LEBIH diutamakan.
            // Dipakai saat >1 promo level yang sama (item vs item, atau cart vs cart) sama-sama cocok.
            $table->integer('priority')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'start_date', 'end_date'], 'promotions_active_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
