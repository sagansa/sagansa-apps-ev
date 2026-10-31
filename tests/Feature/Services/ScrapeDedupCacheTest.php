<?php

namespace Tests\Feature\Services;

use App\Models\Provider;
use App\Services\ScrapeDedupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cache daftar provider di ScrapeDedupService HARUS memakai key tetap.
 *
 * Insiden kuota DB 3 GB (2026-11): key lama menyertakan Provider::count()
 * sehingga setiap penambahan provider menciptakan baris cache BARU berisi
 * snapshot daftar provider — ribuan baris menumpuk hingga 2,3 GB. Key tetap
 * menimpa baris yang sama (upsert), volume cache konstan.
 */
class ScrapeDedupCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Paksa store database agar baris cache benar-benar tertulis ke tabel
        // (default testing = array, tidak menyentuh DB).
        config(['cache.default' => 'database']);
        Cache::flush();
    }

    public function test_provider_cache_key_is_stable_across_provider_count_changes(): void
    {
        Provider::create(['name' => 'Astra', 'status' => 1]);
        (new ScrapeDedupService)->guessProvider('Astra Otopower');

        // Dulu, tiap perubahan jumlah provider mengubah key (count ikut key)
        // dan menciptakan baris cache baru — sumber penumpukan 2,3 GB.
        Provider::create(['name' => 'PLN Mobile', 'status' => 1]);
        Provider::create(['name' => 'Shell', 'status' => 1]);
        (new ScrapeDedupService)->guessProvider('Shell Recharge');
        (new ScrapeDedupService)->guessProvider('PLN UP3 Merauke');

        // Key di tabel memakai prefix cache Laravel → cocokkan dgn %…%.
        $rows = \DB::table('cache')->where('key', 'like', '%scrape:providers%')->count();

        $this->assertLessThanOrEqual(
            1,
            $rows,
            'Cache daftar provider harus menimpa SATU baris tetap, bukan menumpuk per jumlah provider'
        );
    }
}
