<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable + unique: tidak semua user wajib punya phone kalau
            // sudah punya email, tapi kalau diisi harus unik (dipakai untuk
            // login). Unik di-scope per aplikasi (bukan per tenant) karena
            // nomor HP secara alami unik lintas tenant.
            $table->string('phone')->nullable()->unique()->after('email');

            $table->boolean('is_active')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_active']);
        });
    }
};
