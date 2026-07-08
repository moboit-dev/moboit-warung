<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jaga-jaga: kalau ternyata ada user existing dengan tenant_id NULL,
        // migration akan gagal saat ALTER (karena constraint NOT NULL tidak
        // bisa dipasang selama masih ada baris NULL). Cek dulu di sini
        // supaya errornya jelas, bukan error SQL yang membingungkan.
        $orphanUsers = DB::table('users')->whereNull('tenant_id')->count();

        if ($orphanUsers > 0) {
            throw new \RuntimeException(
                "Migration dibatalkan: ada {$orphanUsers} user dengan tenant_id NULL. "
                . "Perbaiki/hapus data tersebut dulu sebelum menjalankan migration ini. "
                . "Cek dengan: DB::table('users')->whereNull('tenant_id')->get();"
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }
};
