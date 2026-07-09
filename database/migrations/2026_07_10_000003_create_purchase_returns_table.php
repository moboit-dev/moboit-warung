<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->string('no_retur');
            $table->date('tanggal_retur');

            // Keterangan MANDATORI - alasan retur, mis. "Expired", "Rusak saat pengiriman".
            $table->text('keterangan');

            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'no_retur']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
