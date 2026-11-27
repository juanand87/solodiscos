<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Services\PerceptualHash;
use Tests\Support\CoverImageFixtures;
class PerceptualHashTest extends TestCase {
    use CoverImageFixtures;
    private function hash(string $bytes): string {$data=(new PerceptualHash)->fromBytes($bytes);imagedestroy($data['image']);return $data['phash'];}
    public function test_identical_and_resized_recompressed_covers_have_close_hashes(): void {
        $hash=new PerceptualHash;$a=$this->hash($this->coverBytes());$same=$this->hash($this->coverBytes());$resized=$this->hash($this->coverBytes(false,256,true));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/',$a);$this->assertSame(0,$hash->distance($a,$same));$this->assertLessThanOrEqual(8,$hash->distance($a,$resized));
        $this->assertGreaterThan(10,$hash->distance($a,$this->hash($this->coverBytes(true))));
    }
    public function test_uniform_images_are_rejected_instead_of_becoming_universal_matches(): void {
        $image=imagecreatetruecolor(100,100);ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
        $this->expectException(\RuntimeException::class);(new PerceptualHash)->fromBytes($bytes);
    }
    public function test_svg_or_non_image_payload_is_never_decoded(): void {
        $this->expectException(\RuntimeException::class);(new PerceptualHash)->fromBytes('<svg><script>alert(1)</script></svg>');
    }
}
