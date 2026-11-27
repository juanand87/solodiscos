<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,ScrapeRun};
use App\Services\{StoreClient,CatalogImporter};

class ShopifyCollectionsTest extends TestCase {
    use RefreshDatabase;
    private function store(): Store { return Store::create(['name'=>'La Tienda Nacional','url'=>'https://latiendanacional.cl','platform'=>'shopify','currency'=>'CLP','logo_checked_at'=>now()]); }
    private function product(int $id,string $title,string $type='VINILO',bool $available=true): array {
        return ['title'=>$title,'product_type'=>$type,'handle'=>'disco-'.$id,'variants'=>[['id'=>$id,'title'=>'Default Title','price'=>'29990','available'=>$available,'sku'=>'SKU'.$id]]];
    }
    public function test_only_configured_music_collections_are_imported_and_suffixes_do_not_split_albums(): void {
        $store=$this->store();$client=\Mockery::mock(StoreClient::class);
        Offer::create(['store_id'=>$store->id,'external_id'=>'3','title'=>'CONGRESO - POR AMOR AL VIENTO','format'=>'cd','currency'=>'CLP','availability'=>'in_stock','url'=>'https://latiendanacional.cl/products/disco-3','checked_at'=>now()]);
        $client->shouldReceive('get')->withArgs(fn($base,$path,$q)=>$path==='/collections/vinilos/products.json')->once()->andReturn(['products'=>[$this->product(1,'CONGRESO - POR AMOR AL VIENTO (LP) | VINILO'),$this->product(2,'(PREVENTA) LOS PRISIONEROS - BOX SET (3LP) | VINILO')]]);
        $client->shouldReceive('get')->withArgs(fn($base,$path,$q)=>$path==='/collections/cds/products.json')->once()->andReturn(['products'=>[$this->product(3,'CONGRESO - POR AMOR AL VIENTO (CD) | CD','CD',false),$this->product(4,'Libro - Música CD','LIBRO')]]);
        $this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);
        $vinyl=Offer::where('external_id','1')->firstOrFail();$cd=Offer::where('external_id','3')->firstOrFail();
        $this->assertSame($vinyl->album_id,$cd->album_id);$this->assertSame('POR AMOR AL VIENTO',$vinyl->album->title);
        $this->assertTrue($vinyl->isPublished());$this->assertFalse($cd->isPublished());
        $preorder=Offer::where('external_id','2')->firstOrFail();$this->assertSame('LOS PRISIONEROS',$preorder->album->artist);$this->assertSame('preorder',$preorder->availability);
        $this->assertNotNull(Offer::where('external_id','4')->firstOrFail()->excluded_reason);
        $this->assertEquals(0,$store->fresh()->shopify_collection);
    }
    public function test_cds_failure_keeps_cursor_and_resumes_without_hiding_existing_stock(): void {
        $store=$this->store();$client=\Mockery::mock(StoreClient::class);
        $client->shouldReceive('get')->withArgs(fn($base,$path,$q)=>$path==='/collections/vinilos/products.json')->once()->andReturn(['products'=>[$this->product(1,'ARTISTA - ALBUM (LP) | VINILO')]]);
        $client->shouldReceive('get')->withArgs(fn($base,$path,$q)=>$path==='/collections/cds/products.json')->once()->andThrow(new \RuntimeException('HTTP 503'));
        $this->app->instance(StoreClient::class,$client);$run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('failed',$run->fresh()->status);$this->assertEquals(1,$store->fresh()->shopify_collection);$this->assertSame(1,Offer::available()->count());
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->withArgs(fn($base,$path,$q)=>$path==='/collections/cds/products.json' && $q['page']===1)->once()->andReturn(['products'=>[]]);
        $this->app->instance(StoreClient::class,$client);$retry=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($retry);
        $this->assertSame('success',$retry->fresh()->status);$this->assertEquals(0,$store->fresh()->shopify_collection);$this->assertSame(1,Offer::available()->count());
    }
}
