<?php

namespace Tests\Unit;

use App\Support\VehicleNameNormalizer;
use Tests\TestCase;

class VehicleNameNormalizerTest extends TestCase
{
    public function test_strip_brand_tokens_membuang_token_nama_brand(): void
    {
        $this->assertSame('S5 EV', VehicleNameNormalizer::stripBrandTokens('MG S5 EV', 'MG'));
        $this->assertSame('CLOUD EV', VehicleNameNormalizer::stripBrandTokens('WULING CLOUD EV', 'WULING'));
    }

    public function test_strip_brand_tokens_tanpa_kecocokan_tetap_utuh(): void
    {
        $this->assertSame('MG4', VehicleNameNormalizer::stripBrandTokens('MG4', 'MG'));
        $this->assertSame('ATTO 3', VehicleNameNormalizer::stripBrandTokens('ATTO 3', ''));
    }
}
