<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\StoreClient;
use Illuminate\Support\Facades\Http;
class ImageRedirectTest extends TestCase {
    private function client(): StoreClient {
        config(['scraping.transport'=>'http']);
        return new class extends StoreClient {
            public function validateUrl(string $url): array {
                if($url==='https://covers.example.test')return ['93.184.216.34'];
                return parent::validateUrl($url);
            }
        };
    }
    public function test_public_image_redirect_is_followed_but_private_destination_is_rejected(): void {
        Http::fake(['https://covers.example.test/start'=>Http::response('',302,['Location'=>'/final']),
            'https://covers.example.test/final'=>Http::response('image-bytes',200)]);
        $this->assertSame('image-bytes',$this->client()->imageBytes('https://covers.example.test/start'));Http::assertSentCount(2);
        Http::fake(['https://covers.example.test/private-redirect'=>Http::response('',302,['Location'=>'http://127.0.0.1/private'])]);
        $this->expectException(\RuntimeException::class);$this->client()->imageBytes('https://covers.example.test/private-redirect');
    }
    public function test_redirect_loops_are_bounded(): void {
        Http::fake(['*'=>Http::response('',302,['Location'=>'/start'])]);
        try{$this->client()->imageBytes('https://covers.example.test/start');$this->fail('Se esperaba limitar los saltos.');}
        catch(\RuntimeException $e){Http::assertSentCount(4);$this->assertStringContainsString('redirige',$e->getMessage());}
    }
}
