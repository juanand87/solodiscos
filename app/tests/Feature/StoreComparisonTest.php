<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,Album,ScrapeRun};
use App\Services\{ProductNormalizer,StoreBranding,StoreClient,CatalogImporter};
class StoreComparisonTest extends TestCase {
    use RefreshDatabase;
    private function offer(Store $store,Album $album,array $extra=[]): Offer {
        return Offer::create(array_merge(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>uniqid(),'title'=>'Pink Floyd - The Wall','description'=>'Descripción propia de '.$store->name,'url'=>$store->url.'/product','price'=>39990,'currency'=>'CLP','format'=>'vinyl','condition'=>'new','availability'=>'in_stock','checked_at'=>now()],$extra));
    }
    private function store(string $name): Store { return Store::create(['name'=>$name,'url'=>'https://'.strtolower($name).'.example','platform'=>'woocommerce','logo_url'=>'https://cdn.example/'.$name.'.png']); }
    private function album(): Album { return Album::create(['artist'=>'Pink Floyd','title'=>'The Wall','match_key'=>app(ProductNormalizer::class)->key('Pink Floyd','The Wall')]); }
    public function test_same_album_format_is_one_card_with_multiple_sellers_and_own_descriptions(): void {
        $album=$this->album();$a=$this->store('Primera');$b=$this->store('Segunda');
        $this->offer($a,$album);$this->offer($b,$album,['price'=>34900,'condition'=>'used']);
        $page=$this->get('/');$page->assertOk()->assertSee('1 publicación')->assertSee('En 2 tiendas')->assertSee('34.900');
        $this->assertEquals(1,substr_count($page->getContent(),'class="album-card"'));
        $this->get('/album/'.$album->id.'/vinyl')->assertOk()->assertSee('Vendido por:')->assertSee('Primera')->assertSee('Segunda')->assertSee('Nuevo')->assertSee('Usado')->assertSee('Descripción propia de Primera')->assertSee('Descripción propia de Segunda')->assertSee('https://cdn.example/Primera.png',false)->assertDontSee('Disponible');
    }
    public function test_cd_and_vinyl_have_separate_cards_pages_and_prices(): void {
        $album=$this->album();$store=$this->store('Primera');$this->offer($store,$album);$this->offer($store,$album,['format'=>'cd','title'=>'CD barato','price'=>9990]);
        $page=$this->get('/');$page->assertOk()->assertSee('2 publicaciones');$this->assertEquals(2,substr_count($page->getContent(),'class="album-card"'));
        $this->get('/album/'.$album->id.'/vinyl')->assertSee('39.990')->assertDontSee('CLP 9.990')->assertDontSee('CD barato');
        $this->get('/album/'.$album->id.'/cd')->assertSee('9.990')->assertDontSee('39.990');
    }
    public function test_unpublished_sellers_are_hidden_from_detail_and_minimum(): void {
        $album=$this->album();$a=$this->store('Visible');$b=$this->store('Agotada');$this->offer($a,$album);
        foreach(['out_of_stock','unknown','preorder','backorder'] as $state) $this->offer($b,$album,['availability'=>$state,'price'=>1000]);
        $this->offer($b,$album,['missing_since'=>now(),'price'=>900]);
        $this->get('/')->assertSee('39.990')->assertSee('En 1 tienda');
        $this->get('/album/'.$album->id.'/vinyl')->assertOk()->assertSee('Visible')->assertDontSee('Agotada')->assertDontSee('1.000');
        $this->assertDatabaseCount('offers',6);
    }
    public function test_branding_prefers_real_logo_and_resolves_relative_paths(): void {
        $branding=app(StoreBranding::class);
        $this->assertEquals('https://shop.example/assets/logo.png',$branding->extract('<link rel="icon" href="/favicon.png"><img class="default-logo" src="/assets/logo.png">','https://shop.example'));
        $this->assertEquals('https://cdn.example/logo.png',$branding->extract('<img src="//cdn.example/logo.png">','https://shop.example'));
        $this->assertEquals('https://shop.example/favicon.png',$branding->extract('<link rel="icon" href="/favicon.png">','https://shop.example'));
        $this->assertNull($branding->extract('<img class="logo" src="javascript:alert(1)">','https://shop.example'));
    }
    public function test_format_and_edition_markers_normalize_without_removing_album_subtitles(): void {
        $n=app(ProductNormalizer::class);
        $this->assertEquals($n->key('Pink Floyd','The Wall'),$n->key('Pink Floyd','The Wall (2LP)'));
        $this->assertEquals($n->key('Pink Floyd','The Wall'),$n->key('Pink Floyd','The Wall (Edición Digipack)'));
        $this->assertEquals('MTV Unplugged (Live in New York)',$n->albumTitle('MTV Unplugged (Live in New York)'));
        $this->assertEquals('used',$n->parse('New Order - Movement','Vinilos Usados')['condition']);
    }
    public function test_notify_me_purchase_control_unpublishes_then_restock_republishes(): void {
        $store=$this->store('Obi');$store->update(['logo_checked_at'=>now()]);
        Offer::create(['store_id'=>$store->id,'external_id'=>'50','title'=>'Pink Floyd - The Wall','format'=>'vinyl','currency'=>'CLP','availability'=>'in_stock','url'=>'https://example.com/product','checked_at'=>now()]);
        $product=['id'=>50,'type'=>'simple','name'=>'Pink Floyd &#8211; The Wall','short_description'=>'<p>VINILO</p>','description'=>'<p>Lista de temas</p>','categories'=>[['name'=>'Vinilos Nuevos']], 'prices'=>['price'=>'34900','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>true,'add_to_cart'=>['text'=>'Avisarme cuando llegue el producto']];
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn([$product]);$this->app->instance(StoreClient::class,$client);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $offer=Offer::firstOrFail();$this->assertEquals('out_of_stock',$offer->availability);$this->assertFalse($offer->isPublished());
        $this->assertEquals('Pink Floyd – The Wall',$offer->title); $this->get('/album/'.$offer->album_id.'/vinyl')->assertNotFound();
        $product['is_in_stock']=true;$product['add_to_cart']['text']='Agregar al carrito';
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn([$product]);$this->app->instance(StoreClient::class,$client);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertDatabaseCount('offers',1);$this->assertTrue($offer->fresh()->isPublished());$this->assertStringContainsString('VINILO',$offer->fresh()->description);
    }
}
