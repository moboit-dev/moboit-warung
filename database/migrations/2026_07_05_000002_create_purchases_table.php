<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('no_pembelian');
            $table->date('tanggal_pembelian');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->enum('jenis_pembayaran', ['cash', 'transfer', 'kredit']);
            $table->date('jatuh_tempo')->nullable(); // hanya diisi kalau kredit
            $table->enum('status', ['draft', 'approved', 'cancelled'])->default('draft');
            $table->decimal('total', 15, 2)->default(0);
            $table->string('bukti_file')->nullable(); // path file PDF/foto nota dari supplier
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            // no_pembelian unik per tenant, bukan unik global
            $table->unique(['tenant_id', 'no_pembelian']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
