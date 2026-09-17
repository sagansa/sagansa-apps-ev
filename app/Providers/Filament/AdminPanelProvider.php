<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->maxContentWidth('full')
            ->brandLogo(fn () => asset('images/logo.png'))
            ->brandLogoHeight('2.2rem')
            ->profile()
            ->navigationGroups([
                'Aplikasi',
                'Master Charger',
                'Referensi Kendaraan',
                'Provider & SPKLU',
                'Konten',
                'OBD2',
                'Admin',
            ])
            ->colors([
                'primary' => Color::Sky,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Widgets\AccountWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make(),
            ]);
    }

    public function boot(): void
    {
        $this->registerMapRenderHooks();
    }

    /**
     * Leaflet + init map Peta Stasiun Charging (ChargingStationMap).
     *
     * Script didaftarkan via render hook (bukan inline di view) karena halaman
     * Filament dinavigasi pakai Livewire `wire:navigate` (SPA) yang MEMBUANG
     * <script> inline dari komponen — akibatnya map tidak pernah inisialisasi
     * saat user pindah lewat sidebar. Render hook menempel di layout yang
     * bertahan, dan listener livewire:navigated menginisialisasi ulang map.
     */
    private function registerMapRenderHooks(): void
    {
        $scope = \App\Filament\Pages\ChargingStationMap::class;

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn () => '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>',
            scopes: $scope,
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn () => Blade::render(<<<'BLADE'
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function () {
    var csReady = false;
    var csData = null;
    var csInited = false;

    function csRender() {
        if (!csReady || !csData || csInited) return;
        var container = document.getElementById('cs-map');
        if (!container) return;
        csInited = true;

        var loading = document.getElementById('cs-map-loading');
        container.style.display = 'block';
        if (loading) loading.style.display = 'none';

        var stations = csData || [];
        var colors = { 'available': '#10B981', 'occupied': '#EF4444', 'offline': '#6B7280', 'unknown': '#D1D5DB' };

        var map = L.map(container).setView([-2.5, 118], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
        }).addTo(map);

        var markers = [];
        for (var i = 0; i < stations.length; i++) {
            var s = stations[i];
            var color = colors[s.level] || '#D1D5DB';
            var m = L.circleMarker([s.lat, s.lng], {
                radius: 6, fillColor: color, color: '#fff', weight: 1, opacity: 1, fillOpacity: 0.85
            });
            m.bindPopup(
                '<div style="min-width:200px">' +
                '<strong>' + csEsc(s.nama) + '</strong><br>' +
                '<small>' + csEsc(s.provinsi) + '</small>' +
                '<hr style="margin:4px 0">' +
                'Status: <strong style="color:' + color + '">' + (s.level || 'unknown') + '</strong><br>' +
                'Slot: ' + s.avail + '/' + s.total + '<br>' +
                'Type: ' + csEsc(s.type) + '<br>' +
                '<a href="/admin/charging-stations/' + s.id + '" style="display:block;margin-top:4px;font-weight:600">Lihat detail &rarr;</a>' +
                '</div>'
            );
            markers.push(m);
            m.addTo(map);
        }
        if (markers.length > 0) {
            map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [20, 20] });
        }
    }

    function csEsc(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function csCheckLeaflet() {
        if (typeof L !== 'undefined') { csReady = true; csRender(); }
        else setTimeout(csCheckLeaflet, 200);
    }

    function csFetch() {
        fetch('/api/v1/spklu?per_page=5000')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                csData = (d.data || []).map(function (s) {
                    return {
                        id: s.id, nama: s.nama_lokasi,
                        lat: s.latitude, lng: s.longitude,
                        level: s.availability_level || 'unknown',
                        avail: s.available_count || 0,
                        total: s.total_konektor || 0,
                        type: s.type_charge || '—',
                        provinsi: s.provinsi || '—'
                    };
                });
                csRender();
            })
            .catch(function (err) {
                console.error('Fetch error:', err);
                var loading = document.getElementById('cs-map-loading');
                if (loading) loading.innerHTML = '<div style="color:#ef4444;text-align:center">Gagal memuat data. Cek console.</div>';
            });
    }

    function csInit() {
        if (csInited) return;
        csReady = false;
        csData = null;
        csCheckLeaflet();
        csFetch();
    }

    document.addEventListener('livewire:navigated', function () { csInit(); });
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', csInit);
    } else {
        csInit();
    }
})();
</script>
BLADE),
            scopes: $scope,
        );
    }
}
