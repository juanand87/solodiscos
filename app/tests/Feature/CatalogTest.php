<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Queue};
use App\Models\{User,Store,Offer,ScrapeRun};
use App\Services\{CatalogImporter,StoreClient,ProductNormalizer,ScrapeDispatcher};
class CatalogTest extends TestCase {
    use RefreshDatabase;
    private function store(): Store { return Store::create(['name'=>'Prueba','url'=>'https://example.com','platform'=>'shopify','currency'=>'CLP']); }
    private function client(array $products): void {
        $mock=\Mockery::mock(StoreClient::class);
        $mock->shouldReceive('get')->withArgs(fn($base,$path,...$rest)=>$path==='/cart.js')->andReturn(['currency'=>'CLP']);
        $mock->shouldReceive('get')->withArgs(fn($base,$path,...$rest)=>$path==='/products.json')->andReturn(['products'=>$products]);
        $this->app->instance(StoreClient::class,$mock);
    }
    private function product(bool $stock=true): array { return ['title'=>'Pink Floyd - The Wall','handle'=>'the-wall','tags'=>['Vinilos','Nuevos'],'body_html'=>'<p>Descripción</p>','variants'=>[['id'=>10,'title'=>'Default Title','price'=>'39990','available'=>$stock]]]; }
    public function test_admin_requires_login_and_catalog_has_empty_state(): void {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/')->assertOk()->assertSee('El catálogo está por comenzar');
        $this->get('/login')->assertOk()->assertSee('Crear administrador');
    }
    public function test_import_is_idempotent_and_sold_out_disappears_from_comparison(): void {
        $store=$this->store(); $this->client([$this->product()]);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $offer=Offer::firstOrFail();
        $this->assertEquals('39990.00',$offer->price); $this->assertEquals('vinyl',$offer->format); $this->assertEquals('new',$offer->condition);
        $this->assertEquals('Pink Floyd',$offer->album->artist);
        $run=ScrapeRun::latest('id')->first();
        $this->assertEquals(1,$run->current_page);$this->assertNotNull($run->started_at);$this->assertNotNull($run->heartbeat_at);
        $this->get('/')->assertOk()->assertSee('The Wall'); $this->get('/album/'.$offer->album_id.'/vinyl')->assertOk()->assertSee('Ver en tienda');
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertDatabaseCount('offers',1); $this->assertDatabaseCount('offer_observations',1);
        $this->client([$this->product(false)]);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertEquals(0,Offer::available()->count()); $this->assertDatabaseCount('offer_observations',2);
        $this->get('/album/'.$offer->album_id.'/vinyl')->assertNotFound();
        $this->client([$this->product(true)]);app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->get('/album/'.$offer->album_id.'/vinyl')->assertOk()->assertSee('Vendido por:')->assertDontSee('Disponible');
    }
    public function test_network_failure_never_removes_stock(): void {
        $store=$this->store(); $this->client([$this->product()]);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $mock=\Mockery::mock(StoreClient::class); $mock->shouldReceive('get')->andThrow(new \RuntimeException('HTTP 503'));
        $this->app->instance(StoreClient::class,$mock);
        $run=ScrapeRun::create(['store_id'=>$store->id]); app(CatalogImporter::class)->run($run);
        $this->assertEquals('failed',$run->fresh()->status); $this->assertEquals('in_stock',Offer::first()->availability);
    }
    public function test_manual_classification_survives_import(): void {
        $store=$this->store(); $this->client([$this->product()]);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id])); $offer=Offer::firstOrFail();
        $this->actingAs(User::factory()->create())->put('/admin/ofertas/'.$offer->id,['artist'=>'Pink Floyd','album'=>'The Wall','format'=>'cd','condition'=>'used'])->assertRedirect('/admin/ofertas');
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertEquals('cd',$offer->fresh()->format); $this->assertEquals('used',$offer->fresh()->condition);
        $this->get('/admin')->assertOk(); $this->get('/admin/tiendas/nueva')->assertOk(); $this->get('/admin/tiendas/'.$store->id.'/editar')->assertOk(); $this->get('/admin/ofertas')->assertOk(); $this->get('/admin/ofertas/'.$offer->id.'/editar')->assertOk();
    }
    public function test_jobs_are_not_duplicated(): void {
        Queue::fake(); $store=$this->store();
        $this->assertTrue(app(ScrapeDispatcher::class)->dispatch($store));
        $this->assertFalse(app(ScrapeDispatcher::class)->dispatch($store)); $this->assertDatabaseCount('scrape_runs',1);
    }
    public function test_woocommerce_minor_units_and_variable_parent_are_handled_safely(): void {
        $store=$this->store(); $store->update(['platform'=>'woocommerce']);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn([
            ['id'=>1,'type'=>'simple','name'=>'Artist - Album CD','prices'=>['price'=>'1599000','currency_minor_unit'=>2,'currency_code'=>'CLP'],'is_in_stock'=>true],
            ['id'=>2,'type'=>'variable','name'=>'CD or vinyl','prices'=>['price'=>'100']]
        ]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertDatabaseCount('offers',1); $this->assertEquals('15990.00',Offer::first()->price);$this->assertEquals('warning',$run->fresh()->status);
    }
    public function test_private_store_urls_are_rejected(): void {
        $this->expectException(\RuntimeException::class); (new StoreClient)->validateUrl('http://127.0.0.1');
    }
    public function test_woocommerce_requests_hidden_sold_out_products_and_records_known_stock_transitions(): void {
        $store=$this->store();$store->update(['platform'=>'woocommerce','logo_checked_at'=>now()]);
        foreach([true,false,true] as $stock){
            $client=\Mockery::mock(StoreClient::class);
            $client->shouldReceive('get')->once()->withArgs(fn($base,$path,$query)=>$query['stock_status']===['instock','outofstock','onbackorder'])->andReturn([
                ['id'=>87021,'type'=>'simple','name'=>'Alphaville - Afternoons In Utopia','categories'=>[['name'=>'Vinilos usados']],
                    'prices'=>['price'=>'16000','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>$stock,'is_purchasable'=>true]
            ]);$this->app->instance(StoreClient::class,$client);
            $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
            $this->assertSame('success',$run->fresh()->status);$offer=$store->offers()->firstOrFail();
            $this->assertSame($stock?'in_stock':'out_of_stock',$offer->availability);$this->assertSame($stock,$offer->isPublished());$this->assertNull($offer->missing_since);
        }
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'restocked','previous_availability'=>'out_of_stock','availability'=>'in_stock']);
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'out_of_stock','previous_availability'=>'in_stock','availability'=>'out_of_stock']);
        $filters=['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()];
        $counts=app(\App\Services\CatalogStatistics::class)->summary($filters)['counts'];
        $this->assertSame(1,$counts['restocked']);$this->assertSame(1,$counts['out_of_stock']);
    }
    public function test_invalid_source_price_does_not_abort_catalogue_and_is_not_published_or_kept_as_a_stale_price(): void {
        $store=$this->store();$store->update(['platform'=>'woocommerce','logo_checked_at'=>now()]);
        $mock=\Mockery::mock(StoreClient::class);$mock->shouldReceive('get')->andReturn([
            ['id'=>42817,'type'=>'simple','name'=>'Bruce Dickinson - The Chemical Wedding Vinilo','prices'=>['price'=>'4050538288575','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>true],
            ['id'=>2,'type'=>'simple','name'=>'Artist - Album CD','prices'=>['price'=>'1599000','currency_minor_unit'=>2,'currency_code'=>'CLP'],'is_in_stock'=>true]
        ]);$this->app->instance(StoreClient::class,$mock);
        $bad=Offer::create(['store_id'=>$store->id,'external_id'=>'42817','title'=>'Old','url'=>'https://example.com/old','price'=>20000,'currency'=>'CLP','format'=>'vinyl','condition'=>'new','availability'=>'in_stock','checked_at'=>now()]);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('warning',$run->fresh()->status);$this->assertSame(2,$run->fresh()->imported);
        $this->assertStringContainsString('1 publicaciones sin precio válido',$run->fresh()->message);
        $bad->refresh();$this->assertNull($bad->price);$this->assertFalse($bad->isPublished());$this->assertNull($bad->missing_since);
        $this->assertSame('4050538288575',$bad->source_metadata['price_issue']['reported']);
        $this->assertEquals(15990,Offer::where('external_id','2')->firstOrFail()->price);
        $this->client([array_replace_recursive($this->product(),['variants'=>[['id'=>42817,'price'=>'30000']]])]);$store->update(['platform'=>'shopify']);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertEquals(30000,$bad->fresh()->price);$this->assertArrayNotHasKey('price_issue',$bad->fresh()->source_metadata);
    }
    public function test_ambiguous_format_and_condition_are_not_guessed(): void {
        $parsed=(new ProductNormalizer)->parse('Artist - Album CD Vinilo','Nuevos Usados');
        $this->assertEquals('unknown',$parsed['format']); $this->assertEquals('unknown',$parsed['condition']);
    }
    public function test_admin_can_add_edit_and_pause_a_store_without_duplicates(): void {
        $this->actingAs(User::factory()->create());
        $mock=\Mockery::mock(StoreClient::class);$mock->shouldReceive('validateUrl')->andReturn(['93.184.215.14']);$this->app->instance(StoreClient::class,$mock);
        $data=['name'=>'Nueva tienda','url'=>'https://example.com/','platform'=>'woocommerce','currency'=>'CLP','default_condition'=>'unknown','interval_hours'=>48,'active'=>1];
        $this->post('/admin/tiendas',$data)->assertRedirect('/admin'); $store=Store::firstOrFail();
        $this->assertEquals('https://example.com',$store->url);
        $this->post('/admin/tiendas',$data)->assertSessionHasErrors('url');$this->assertDatabaseCount('stores',1);
        $data['active']=0; $data['interval_hours']=72;
        $this->put('/admin/tiendas/'.$store->id,$data)->assertRedirect('/admin');
        $this->assertFalse($store->fresh()->active); $this->assertEquals(72,$store->fresh()->interval_hours);
    }
    public function test_partial_import_resumes_from_pending_page(): void {
        $store=$this->store(); $products=[];
        for($i=1;$i<=100;$i++) { $p=$this->product();$p['variants'][0]['id']=$i;$products[]=$p; }
        $mock=\Mockery::mock(StoreClient::class);$mock->shouldReceive('get')->andReturnUsing(function($base,$path,$query)use($products) {
            if($query['page']===1) return ['products'=>$products]; throw new \RuntimeException('HTTP 429');
        });$this->app->instance(StoreClient::class,$mock);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertEquals(2,$store->fresh()->next_page);$this->assertDatabaseCount('offers',100);
        $mock=\Mockery::mock(StoreClient::class);$mock->shouldReceive('get')->once()->withArgs(fn($base,$path,$query)=>$query['page']===2)->andReturn(['products'=>[]]);$this->app->instance(StoreClient::class,$mock);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertEquals('success',$run->fresh()->status);$this->assertEquals(1,$store->fresh()->next_page);$this->assertNull($store->fresh()->cycle_started_at);
    }
    public function test_woocommerce_shop_url_uses_installation_base_and_rejects_duplicate_shop_paths(): void {
        $this->actingAs(User::factory()->create());
        $mock=\Mockery::mock(StoreClient::class);$mock->shouldReceive('validateUrl')->once()->with('https://example.com/wordpress')->andReturn(['93.184.215.14']);$this->app->instance(StoreClient::class,$mock);
        $data=['name'=>'Tienda','url'=>'https://example.com/wordpress/tienda/','platform'=>'woocommerce','currency'=>'CLP','default_condition'=>'unknown','interval_hours'=>48,'active'=>1];
        $this->post('/admin/tiendas',$data)->assertRedirect('/admin');
        $this->assertSame('https://example.com/wordpress',Store::firstOrFail()->url);
        foreach(['tienda','shop'] as $path){$data['url']='https://example.com/wordpress/'.$path.'/';$this->post('/admin/tiendas',$data)->assertSessionHasErrors('url');}
        $this->assertDatabaseCount('stores',1);
    }
    public function test_duplicate_warning_names_existing_paused_store_across_www_protocol_and_shop_paths(): void {
        $store=Store::create(['name'=>'Tienda existente','url'=>'https://example.com','platform'=>'woocommerce','currency'=>'CLP','active'=>false]);
        $this->actingAs(User::factory()->create());$mock=\Mockery::mock(StoreClient::class);$mock->shouldNotReceive('validateUrl');$this->app->instance(StoreClient::class,$mock);
        $data=['name'=>'Otro nombre','platform'=>'shopify','currency'=>'CLP','default_condition'=>'unknown','interval_hours'=>48,'active'=>1];
        foreach(['http://www.example.com/','https://WWW.example.com/tienda/','https://example.com/shop'] as $url){
            $this->post('/admin/tiendas',$data+['url'=>$url])->assertSessionHasErrors(['url'=>'Esta tienda ya está registrada como «Tienda existente» (https://example.com), pausada. Usa su registro existente para actualizar el catálogo.']);
        }
        $this->assertDatabaseCount('stores',1);$this->assertFalse($store->fresh()->active);
        $this->assertNull(app(\App\Services\StoreDuplicates::class)->find('https://example.com',$store->id));
        $this->assertNull(app(\App\Services\StoreDuplicates::class)->find('https://example.com/otra-instalacion'));
    }
}
