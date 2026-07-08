<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('key');   // contoh: "nomor_polisi", "tanggal_selesai", "nomor_meja"
            $table->text('value')->nullable();
            $table->timestamps();

            $table->index('transaction_id');
            $table->index(['transaction_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_meta');
    }
};