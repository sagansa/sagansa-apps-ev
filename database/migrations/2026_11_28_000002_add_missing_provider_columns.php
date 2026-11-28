<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menutup gap schema tabel providers: kolom-kolom ini dipakai Filament
 * (ProviderResource) dan API tapi tidak pernah dibuat oleh migrasi mana pun —
 * sebelumnya hanya ditambahkan manual di produksi. Setiap kolom dijaga
 * hasColumn() sehingga no-op di server yang sudah memilikinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            foreach ([
                'email' => fn () => $table->string('email')->nullable()->after('contact'),
                'public' => fn () => $table->tinyInteger('public')->default(1)->after('status'),
                'price' => fn () => $table->string('price')->nullable()->after('address'),
                'admin_fee' => fn () => $table->string('admin_fee')->nullable()->after('price'),
                'tax' => fn () => $table->string('tax')->nullable()->after('admin_fee'),
                'web' => fn () => $table->string('web')->nullable()->after('tax'),
                'google' => fn () => $table->string('google')->nullable()->after('web'),
                'ios' => fn () => $table->string('ios')->nullable()->after('google'),
            ] as $column => $define) {
                if (! Schema::hasColumn('providers', $column)) {
                    $define();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('providers')) {
            return;
        }

        Schema::table('providers', function (Blueprint $table) {
            foreach (['web', 'google', 'ios', 'tax', 'admin_fee', 'price', 'public', 'email'] as $column) {
                if (Schema::hasColumn('providers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
