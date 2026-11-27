<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,ScrapeRun,User};
use App\Services\{BsaleCatalog,StoreClient,CatalogImporter};
class BsaleCatalogTest extends TestCase {
    use RefreshDatabase;
    public function test_billboard_wrapped_json_and_singular_cd_collection_are_supported_without_script_execution(): void {
        $catalog=app(BsaleCatalog::class);
        $this->assertSame(['vinilos','cd'],$catalog->collections('https://www.billboard.cl'));
        $wrapped='<script>window.INIT.collections.push('.json_encode(['items'=>[$this->item()]]).');</script>';
        $page=$catalog->parse($wrapped,'https://www.billboard.cl','vinilos');
        $this->assertCount(1,$page['products']);
        $this->assertSame(44993,$page['products'][0]['price']);
        $this->expectException(\RuntimeException::class);
        $catalog->parse('<script>window.INIT.collections.push({items:fetch("/secret")});</script>','https://www.billboard.cl','vinilos');
    }
    private function item(array $extra=[]): array { return array_replace(['productId'=>23822,'variantId'=>130511,'title'=>'VINILO ARIANA GRANDE/ PETAL (WHITE VINYL) 1LP','link'=>'/product/petal','finalPrice'=>44993,'variantPrice'=>50412,'fpWithoutDiscount'=>59990,'totalStock'=>10,'stockControl'=>1,'allowNegativeStock'=>0,'defaultImage'=>'https://cdn.example/petal.jpg','description'=>'<p>Descripción</p>'],$extra); }
    private function html(array $items,string $next=''): string { return '<script type="application/json" id="coll_data_base">'.json_encode(['name'=>'Colección','items'=>$items]).'</script>'.($next?'<link rel="next" href="'.$next.'">':''); }
    public function test_public_collection_fields_price_stock_title_and_pagination_are_read_without_running_scripts(): void {
        $page=app(BsaleCatalog::class)->parse($this->html([$this->item()],'/collection/vinilos?limit=100&page=2'),'https://www.plazamusica.cl','vinilos');
        $this->assertTrue($page['has_next']);$product=$page['products'][0];$this->assertEquals(44993,$product['price']);$this->assertSame('in_stock',$product['availability']);
        $this->assertSame('ARIANA GRANDE',$product['source']['artist']);$this->assertSame('https://www.plazamusica.cl/product/petal',$product['url']);
    }
    public function test_zero_unknown_and_backorder_stock_are_never_treated_as_available(): void {
        $items=[$this->item(['totalStock'=>0]),$this->item(['totalStock'=>null]),$this->item(['totalStock'=>0,'allowNegativeStock'=>1])];
        $page=app(BsaleCatalog::class)->parse($this->html($items),'https://www.plazamusica.cl','vinilos');
        $this->assertSame(['out_of_stock','unknown','backorder'],array_column($page['products'],'availability'));
    }
    public function test_missing_catalogue_is_an_error_instead_of_an_empty_success(): void {
        $this->expectException(\RuntimeException::class);app(BsaleCatalog::class)->parse('<html>Error</html>','https://www.plazamusica.cl','vinilos');
    }
    public function test_invalid_store_record_is_reported_and_never_imported_as_a_real_product(): void {
        $page=app(BsaleCatalog::class)->parse($this->html([$this->item(),$this->item(['variantId'=>36337,'title'=>'','link'=>'/product/'])]),'https://www.plazamusica.cl','cds');
        $this->assertCount(1,$page['products']);$this->assertSame(1,$page['skipped']);
    }
    public function test_bsale_records_new_products_stock_transitions_and_repeated_imports_without_duplicate_events(): void {
        $store=Store::create(['name'=>'Plaza Música','url'=>'https://www.plazamusica.cl','platform'=>'bsale','currency'=>'CLP','logo_checked_at'=>now()]);
        foreach([1,0,1,1] as $step=>$stock){
            $items=[$this->item(['totalStock'=>$stock])];if($step>0)$items[]=$this->item(['variantId'=>999,'title'=>'VINILO ARTIST/ NEW ALBUM 1LP','totalStock'=>1]);
            $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('html')->withArgs(fn($base,$path)=>$path==='/collection/vinilos')->andReturn($this->html($items));$client->shouldReceive('html')->withArgs(fn($base,$path)=>$path==='/collection/cds')->andReturn($this->html([]));$this->app->instance(StoreClient::class,$client);
            $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('success',$run->fresh()->status);
        }
        $this->assertDatabaseCount('offer_events',5);
        $filters=['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()];$counts=app(\App\Services\CatalogStatistics::class)->summary($filters)['counts'];
        $this->assertSame(1,$counts['added']);$this->assertSame(1,$counts['out_of_stock']);$this->assertSame(1,$counts['restocked']);$this->assertEquals(1,$counts['possible_sale']);
    }
    public function test_both_collections_use_common_import_grouping_exclusions_and_stock_updates(): void {
        $store=Store::create(['name'=>'Plaza Música','url'=>'https://www.plazamusica.cl','platform'=>'bsale','currency'=>'CLP','logo_checked_at'=>now()]);
        $client=\Mockery::mock(StoreClient::class);
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/vinilos')->andReturn($this->html([$this->item(),$this->item(['variantId'=>20,'title'=>'LIBRO ARTISTA/ BIOGRAFIA','link'=>'/product/libro'])]));
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/cds')->andReturn($this->html([$this->item(['variantId'=>30,'title'=>'CD AC/DC / BACK IN BLACK 1CD','link'=>'/product/back-in-black','totalStock'=>0])]));
        $this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);$this->assertEquals(2,$run->fresh()->imported);$this->assertDatabaseCount('offers',2);
        $offer=Offer::where('external_id','130511')->firstOrFail();$this->assertSame('PETAL',$offer->album->title);$this->assertSame('ARIANA GRANDE',$offer->album->artist);$this->assertSame('vinyl',$offer->format);$this->assertTrue($offer->isPublished());
        $this->assertNotNull(Offer::where('external_id','20')->first()->excluded_reason);
        $this->assertDatabaseMissing('offers',['store_id'=>$store->id,'external_id'=>'30']);
        $this->assertEquals(0,$store->fresh()->bsale_collection);$this->assertEquals(1,$store->fresh()->next_page);
        $this->actingAs(User::factory()->create())->get('/admin/tiendas/nueva')->assertOk()->assertSee('Bsale');
    }
    public function test_failure_in_cds_resumes_that_collection_without_repeating_vinyl(): void {
        $store=Store::create(['name'=>'Plaza Música','url'=>'https://www.plazamusica.cl','platform'=>'bsale','currency'=>'CLP','logo_checked_at'=>now()]);
        $client=\Mockery::mock(StoreClient::class);
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/vinilos')->once()->andReturn($this->html([$this->item()]));
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/cds')->once()->andThrow(new \RuntimeException('HTTP 503'));
        $this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('failed',$run->fresh()->status);$this->assertEquals(1,$store->fresh()->bsale_collection);$this->assertEquals(1,Offer::available()->count());
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/cds' && $query['page']===1)->once()->andReturn($this->html([]));$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);$this->assertEquals(0,$store->fresh()->bsale_collection);$this->assertEquals(1,Offer::available()->count());
    }
}
