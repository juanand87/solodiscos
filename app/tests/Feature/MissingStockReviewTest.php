<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer};
use App\Services\{MissingStockReview,StockAvailabilityVerifier,StoreClient,CatalogStatistics};

class MissingStockReviewTest extends TestCase {
    use RefreshDatabase;
    private function schema(array $products): string {return '<script type="application/ld+json">'.json_encode(['@graph'=>$products]).'</script>';}
    private function product(string $url,string $state): array {return ['@type'=>'Product','url'=>$url,'offers'=>['url'=>$url,'availability'=>'https://schema.org/'.$state]];}
    public function test_product_schema_requires_the_correct_url_and_unambiguous_stock_not_recommendations(): void {
        $service=app(StockAvailabilityVerifier::class);$url='https://example.com/product/disc';
        $this->assertSame('out_of_stock',$service->fromProductSchema($this->schema([$this->product($url,'OutOfStock')]),$url));
        $this->assertSame('in_stock',$service->fromProductSchema($this->schema([$this->product($url,'InStock'),$this->product('https://example.com/product/other','OutOfStock')]),$url));
        $this->assertNull($service->fromProductSchema($this->schema([$this->product('https://example.com/product/other','OutOfStock')]),$url));
        $this->assertNull($service->fromProductSchema($this->schema([$this->product($url,'OutOfStock'),$this->product($url,'InStock')]),$url));
        $this->assertNull($service->fromProductSchema('<p>Agotado</p>',$url));
        $this->assertNull($service->fromProductSchema($this->schema([$this->product('https://other.example/product/disc','OutOfStock')]),$url));
    }
    public function test_confirmed_missing_stock_records_once_and_does_not_refresh_price_or_publish(): void {
        $store=Store::create(['name'=>'Store','url'=>'https://example.com','platform'=>'bsale','currency'=>'CLP']);
        $offer=Offer::create(['store_id'=>$store->id,'external_id'=>'20','title'=>'Artist - Album','format'=>'cd','currency'=>'CLP','price'=>15000,'availability'=>'in_stock','url'=>'https://example.com/product/disc','checked_at'=>now()->subDays(2),'missing_since'=>now()->subDay()]);
        $original=$offer->getRawOriginal('checked_at');$verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->once()->andReturn('out_of_stock');$this->app->instance(StockAvailabilityVerifier::class,$verifier);
        $service=app(MissingStockReview::class);$this->assertSame(1,$service->review($store)['changed']);
        $offer->refresh();$this->assertSame('out_of_stock',$offer->availability);$this->assertEquals(15000,$offer->price);$this->assertSame($original,$offer->getRawOriginal('checked_at'));$this->assertNotNull($offer->missing_since);$this->assertFalse($offer->isPublished());
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'out_of_stock','previous_availability'=>'in_stock','availability'=>'out_of_stock']);
        $this->assertSame(0,$service->review($store)['checked']);$this->assertDatabaseCount('offer_events',1);
        $filters=['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()];$this->assertSame(1,app(CatalogStatistics::class)->summary($filters)['counts']['out_of_stock']);
    }
    public function test_failed_lookup_never_infers_a_sale_and_checks_have_a_cooldown(): void {
        $store=Store::create(['name'=>'Store','url'=>'https://example.com','platform'=>'bsale','currency'=>'CLP']);
        $offer=Offer::create(['store_id'=>$store->id,'external_id'=>'20','title'=>'Artist - Album','format'=>'cd','currency'=>'CLP','price'=>15000,'availability'=>'in_stock','url'=>'https://example.com/product/disc','checked_at'=>now()->subDay(),'missing_since'=>now()]);
        $verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->once()->andThrow(new \RuntimeException('404'));$this->app->instance(StockAvailabilityVerifier::class,$verifier);
        $service=app(MissingStockReview::class);$this->assertSame(1,$service->review($store)['unconfirmed']);$this->assertSame(0,$service->review($store)['checked']);
        $this->assertSame('in_stock',$offer->fresh()->availability);$this->assertDatabaseCount('offer_events',0);
    }
}
