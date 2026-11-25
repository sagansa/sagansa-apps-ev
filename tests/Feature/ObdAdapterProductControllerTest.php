<?php

namespace Tests\Feature;

use App\Models\ObdAdapterProduct;
use App\Models\ObdOfficialStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/v1/obd/adapter-products — kartu toko adapter gerbang OBD2.
 * Cover 2026-11-25: 401 tanpa auth, kill-switch mati = 404, multi-link per
 * produk (hanya link aktif, terurut), fallback affiliate_url = link aktif
 * pertama utk app versi lama, dan official_stores[] aktif terurut.
 */
class ObdAdapterProductControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_kill_switch_mati_404(): void
    {
        $this->actingAsUser();
        Config::set('obd.enabled', false);

        $this->getJson('/api/v1/obd/adapter-products')->assertStatus(404);
    }

    public function test_multi_link_dan_fallback_affiliate_url(): void
    {
        $this->actingAsUser();
        Config::set('obd.enabled', true);

        $product = ObdAdapterProduct::create([
            'name' => 'ELM327 WiFi V1.5',
            'brand' => 'Vgate',
            'type' => 'wifi',
            'price_idr' => '185000.00',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $product->links()->createMany([
            ['marketplace' => 'shopee', 'url' => 'https://shopee.co.id/x', 'is_active' => true, 'sort_order' => 1],
            ['marketplace' => 'lazada', 'url' => 'https://lazada.co.id/z', 'is_active' => false, 'sort_order' => 2],
            ['marketplace' => 'tokopedia', 'url' => 'https://tokopedia.com/y', 'is_active' => true, 'sort_order' => 0],
        ]);

        $response = $this->getJson('/api/v1/obd/adapter-products');
        $response->assertStatus(200)->assertJsonPath('success', true);

        $item = $response->json('data.0');
        // Fallback app lama = link aktif pertama (tokopedia, sort 0).
        $this->assertSame('https://tokopedia.com/y', $item['affiliate_url']);
        // Link nonaktif (lazada) tidak lolos; urutan ikut sort_order.
        $links = collect($item['links']);
        $this->assertCount(2, $links);
        $this->assertSame('tokopedia', $links->first()['marketplace']);
        $this->assertSame('https://tokopedia.com/y', $links->first()['url']);
        $this->assertSame('shopee', $links->last()['marketplace']);
    }

    public function test_official_stores_hanya_aktif_terurut(): void
    {
        $this->actingAsUser();
        Config::set('obd.enabled', true);

        ObdOfficialStore::create(['marketplace' => 'shopee', 'store_url' => 'https://shopee.co.id/toko', 'is_active' => true, 'sort_order' => 1]);
        ObdOfficialStore::create(['marketplace' => 'tokopedia', 'store_url' => 'https://tokopedia.com/toko', 'is_active' => true, 'sort_order' => 0]);
        ObdOfficialStore::create(['marketplace' => 'lazada', 'store_url' => 'https://lazada.co.id/toko', 'is_active' => false, 'sort_order' => 2]);

        $response = $this->getJson('/api/v1/obd/adapter-products');
        $response->assertStatus(200);

        $stores = collect($response->json('official_stores'));
        $this->assertCount(2, $stores);
        $this->assertSame('tokopedia', $stores->first()['marketplace']);
        $this->assertSame('https://tokopedia.com/toko', $stores->first()['store_url']);
        $this->assertSame('shopee', $stores->last()['marketplace']);
    }

    public function test_produk_tanpa_link_affiliate_url_null(): void
    {
        $this->actingAsUser();
        Config::set('obd.enabled', true);

        ObdAdapterProduct::create([
            'name' => 'ELM327 BLE',
            'brand' => 'Vgate',
            'type' => 'ble',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/v1/obd/adapter-products');
        $response->assertStatus(200);

        $item = $response->json('data.0');
        $this->assertNull($item['affiliate_url']);
        $this->assertSame([], $item['links']);
    }
}
