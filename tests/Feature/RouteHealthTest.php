<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteHealthTest extends TestCase
{
    public function test_route_names_are_unique(): void
    {
        // route:cache gagal serialisasi bila ada nama route yang bentrok —
        // jaga agar tidak terulang (lihat kasus my/charging-locations vs charging-locations).
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values();

        $duplicates = $names->duplicates()->all();

        $this->assertSame([], $duplicates, 'Nama route duplikat: '.implode(', ', $duplicates));
    }
}
