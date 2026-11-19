<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function panel(string $id): Panel
    {
        return Filament::getPanel($id);
    }

    public function test_super_admin_can_access_admin_panel(): void
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->assertTrue($user->canAccessPanel($this->panel('admin')));
    }

    public function test_admin_role_cannot_access_admin_panel(): void
    {
        // Keputusan owner 2026-11-19: /admin hanya untuk super_admin — role
        // admin dulu boleh masuk panel, sekarang harus ditolak.
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->assertFalse($user->canAccessPanel($this->panel('admin')));
    }

    public function test_regular_user_cannot_access_admin_panel(): void
    {
        Role::findOrCreate('user', 'web');
        $user = User::factory()->create();
        $user->syncRoles(['user']);

        $this->assertFalse($user->canAccessPanel($this->panel('admin')));
    }

    public function test_user_without_any_role_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([]);

        $this->assertFalse($user->canAccessPanel($this->panel('admin')));
    }

    public function test_http_admin_panel_menolak_role_admin_dan_menerima_super_admin(): void
    {
        // Verifikasi HTTP nyata (bukan cuma canAccessPanel): middleware
        // Authenticate milik Filament harus membalas 403 untuk role admin,
        // dan super_admin tetap bisa membuka /admin (200 atau redirect).
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin')->assertForbidden();

        Role::findOrCreate('super_admin', 'web');
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $response = $this->actingAs($superAdmin)->get('/admin');
        $this->assertContains($response->status(), [200, 302]);
    }
}
