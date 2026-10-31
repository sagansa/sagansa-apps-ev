<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Smoke: halaman admin OCM (browse POI + review operator match) harus render
 * utk super_admin — inilah jalur verifikasi backend setelah ocm:sync/hydrate.
 */
class OcmResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocm_resources_render_for_super_admin(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $urls = [
            '/admin/panel/openchargemap-pois',
            '/admin/panel/ocm-operator-matches',
            '/admin/panel/charging-stations', // filter source/country baru tidak boleh merusak
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url);
            $this->assertEquals(200, $response->getStatusCode(), "URL gagal: {$url}");
        }
    }
}
