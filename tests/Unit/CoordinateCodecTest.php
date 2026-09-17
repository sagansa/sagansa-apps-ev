<?php

namespace Tests\Unit;

use App\Support\CoordinateCodec;
use PHPUnit\Framework\TestCase;

class CoordinateCodecTest extends TestCase
{
    private CoordinateCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        // Secret eksplisit (bukan env) agar test tidak bergantung konfigurasi.
        $this->codec = new CoordinateCodec('s4nk4ns3cr3t', 'v1');
    }

    public function test_encode_matches_golden_vectors(): void
    {
        // Vector ini juga tertanam di CoordinateCodecTest.kt (mobile shared) —
        // bukti determinisme lintas bahasa PHP/Kotlin/Swift. Bila formula atau
        // secret berubah, test ini dan versi mobile-nya gagal bersamaan.
        $this->assertSame('oFGubuN0sxgmx4C8O2P+BZCu8B1sOrdK', $this->codec->encode(7, -6.56, 106.86));
        $this->assertSame('6qoIN1NUBz8hKv7s9prWnhv/xDtP/tl9', $this->codec->encode(42, -6.2, 106.8));
        $this->assertSame('305RYNYlZ8onfIJq0/zpxYkwoQqazgKH', $this->codec->encode(123, -5.1477, 119.4327));
    }

    public function test_decode_round_trips_golden_vectors(): void
    {
        $this->assertSame(['lat' => -6.56, 'lng' => 106.86], $this->codec->decode(7, 'oFGubuN0sxgmx4C8O2P+BZCu8B1sOrdK'));
        $this->assertSame(['lat' => -6.2, 'lng' => 106.8], $this->codec->decode(42, '6qoIN1NUBz8hKv7s9prWnhv/xDtP/tl9'));
        $this->assertSame(['lat' => -5.1477, 'lng' => 119.4327], $this->codec->decode(123, '305RYNYlZ8onfIJq0/zpxYkwoQqazgKH'));
    }

    public function test_encode_is_deterministic_and_id_scoped(): void
    {
        $a = $this->codec->encode(42, -6.2, 106.8);
        $b = $this->codec->encode(42, -6.2, 106.8);
        $c = $this->codec->encode(43, -6.2, 106.8);

        $this->assertSame($a, $b, 'Encode id+koordinat sama harus deterministik');
        $this->assertNotSame($a, $c, 'id berbeda harus menghasilkan ciphertext berbeda');
    }

    public function test_decode_rejects_invalid_input(): void
    {
        $this->assertNull($this->codec->decode(1, 'not-valid-base64!!!'));
        $this->assertNull($this->codec->decode(1, 'AAAA'), 'Payload acak bukan "lat,lng" valid');
    }

    public function test_decode_with_different_secret_fails(): void
    {
        $other = new CoordinateCodec('secret-lain', 'v1');
        $encoded = $other->encode(42, -6.2, 106.8);

        $this->assertNull($this->codec->decode(42, $encoded), 'Kunci salah tidak boleh menghasilkan koordinat');
    }

    public function test_decode_out_of_range_coordinates_rejected(): void
    {
        // Encode payload "999,999" secara manual memakai keystream codec →
        // hasil decode di luar batas geografis → null.
        $reflection = new \ReflectionMethod($this->codec, 'buildKeystream');
        $keystream = $reflection->invoke($this->codec, '1', 7);
        $xored = '999,999' ^ $keystream;

        $this->assertNull($this->codec->decode(1, base64_encode($xored)));
    }
}
