<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,Offer,ScrapeRun,User};
use App\Services\{CatalogImporter,StoreClient,StockQuantities,StockAvailabilityVerifier,CatalogStatistics};

class StockQuantitiesTest extends TestCase {
    use RefreshDatabase;
    private function store(string $name='Shop'): Store {return Store::create(['name'=>$name,'url'=>'https://'.strtolower($name).'.example','platform'=>'shopify','currency'=>'CLP','logo_checked_at'=>now()]);}
    private function product(int $id=1,?int $quantity=null,bool $available=true): array {
        $variant=['id'=>$id,'title'=>'Default Title','price'=>'10000','available'=>$available];if($quantity!==null)$variant['inventory_quantity']=$quantity;
        return ['title'=>'Artist - Album '.$id,'handle'=>'album-'.$id,'product_type'=>'CD','variants'=>[$variant]];
    }
    private function runImport(Store $store,array $products): ScrapeRun {
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>$products]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('success',$run->fresh()->status,$run->fresh()->message??'');return $run;
    }
    private function filters(Store $store,array $extra=[]): array {return $extra+['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()];}
    public function test_reported_quantities_decrease_only_once_sum_units_and_are_separate_per_store_and_exported(): void {
        $store=$this->store();$other=$this->store('Other');$this->runImport($other,[$this->product(1,20)]);
        foreach([5,3,3,7,4] as $qty)$this->runImport($store,[$this->product(1,$qty)]);
        $offer=$store->offers()->firstOrFail();$this->assertSame(4,$offer->stock_quantity);$this->assertSame('reported',$offer->stock_quantity_source);
        $sales=DB::table('offer_events')->where('store_id',$store->id)->where('type','possible_sale')->get();$this->assertCount(2,$sales);$this->assertEquals([2,3],$sales->pluck('units')->all());
        $this->assertSame(20,$other->offers()->first()->stock_quantity);
        $counts=app(CatalogStatistics::class)->summary($this->filters($store))['counts'];$this->assertEquals(5,$counts['possible_sale']);
        $this->actingAs(User::factory()->create());$response=$this->get(route('statistics.index',$this->filters($store,['type'=>'possible_sale'])))->assertOk()->assertSee('Vendidos')->assertSee('Baja de cantidad informada')->assertSee('stock 5 → 3');
        $csv=$this->get(route('statistics.export',$this->filters($store,['type'=>'possible_sale'])))->assertOk()->streamedContent();
        $this->assertStringContainsString('Posibles copias vendidas',$csv);$this->assertStringContainsString('Cantidad informada',$csv);$this->assertStringContainsString(';5;3;2;',$csv);
    }
    public function test_estimated_availability_uses_one_and_does_not_sell_on_quantity_reporting_changes(): void {
        $store=$this->store();
        foreach([$this->product(1,5),$this->product(),$this->product(1,4),$this->product(1,2)] as $p)$this->runImport($store,[$p]);
        $this->assertEquals(2,DB::table('offer_events')->where('type','possible_sale')->sum('units'));
        $this->runImport($store,[$this->product()]);$this->runImport($store,[$this->product(1,null,false)]);$this->runImport($store,[$this->product(1,null,false)]);
        $sales=DB::table('offer_events')->where('type','possible_sale')->get();$this->assertCount(2,$sales);$this->assertEquals(1,$sales[1]->units);$this->assertSame('estimated_availability',$sales[1]->stock_evidence);
    }
    public function test_complete_catalogue_disappearance_counts_last_quantity_and_never_double_counts(): void {
        $store=$this->store();$this->runImport($store,[$this->product(1,7)]);
        $verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->andReturnNull();$this->app->instance(StockAvailabilityVerifier::class,$verifier);
        $this->travel(1)->minutes();$this->runImport($store,[]);$this->travel(1)->minutes();$this->runImport($store,[]);
        $offer=$store->offers()->firstOrFail();$this->assertSame(0,$offer->stock_quantity);$this->assertSame('missing',$offer->stock_quantity_source);
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'possible_sale','stock_before'=>7,'stock_after'=>0,'units'=>7,'stock_evidence'=>'catalog_disappearance']);
        $this->runImport($store,[$this->product(1,0,false)]);
        $this->assertSame(1,DB::table('offer_events')->where('type','possible_sale')->count());
    }
    public function test_disappearance_uses_two_reported_copies_or_one_when_quantity_was_estimated(): void {
        foreach([2,null] as $quantity){
            $store=$this->store($quantity===2?'Reported':'Estimated');$this->runImport($store,[$this->product(1,$quantity)]);
            $verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->andReturnNull();$this->app->instance(StockAvailabilityVerifier::class,$verifier);
            $this->travel(1)->minutes();$this->runImport($store,[]);$this->travel(1)->minutes();$this->runImport($store,[]);
            $this->assertDatabaseHas('offer_events',['store_id'=>$store->id,'type'=>'possible_sale','stock_before'=>$quantity??1,'stock_after'=>0,'units'=>$quantity??1,'stock_evidence'=>'catalog_disappearance']);
            $this->assertEquals($quantity??1,app(CatalogStatistics::class)->summary($this->filters($store))['counts']['possible_sale']);
            $this->assertSame(1,DB::table('offer_events')->where('store_id',$store->id)->where('type','possible_sale')->count());
        }
    }
    public function test_failed_or_abnormally_small_catalogue_never_infers_missing_sales_and_positive_detail_prevents_false_sale(): void {
        $store=$this->store();$products=[];for($i=1;$i<=11;$i++)$products[]=$this->product($i,2);$this->runImport($store,$products);
        $this->travel(1)->minutes();$client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>[]]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('failed',$run->fresh()->status);$this->assertSame(0,DB::table('offer_events')->where('type','possible_sale')->count());$this->assertSame(0,$store->offers()->whereNotNull('missing_since')->count());
        $small=$this->store('Small');$this->runImport($small,[$this->product(1,2)]);$verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->andReturn('in_stock');$this->app->instance(StockAvailabilityVerifier::class,$verifier);
        $this->travel(1)->minutes();$this->runImport($small,[]);$this->assertSame(0,DB::table('offer_events')->where('type','possible_sale')->count());
    }
    public function test_native_quantity_parsing_and_baseline_do_not_invent_historical_sales(): void {
        $q=app(StockQuantities::class);$this->assertSame(5,$q->wooReported(['stock_quantity'=>5]));$this->assertSame(1,$q->wooReported(['low_stock_remaining'=>1]));
        $this->assertSame(3,$q->wooReported(['stock_availability'=>['text'=>'3 disponibles']]));$this->assertNull($q->wooReported(['stock_availability'=>['text'=>'Hay existencias']]));
        foreach([-1,'NaN',1.5,'999999999999999999999'] as $value)$this->assertNull($q->integer($value));
        $store=$this->store();$offer=Offer::create(['store_id'=>$store->id,'external_id'=>'legacy','title'=>'Old','format'=>'cd','currency'=>'CLP','availability'=>'in_stock','url'=>'https://example.com/product','checked_at'=>now()]);
        $this->artisan('stocks:baseline')->assertSuccessful();$this->assertSame(1,$offer->fresh()->stock_quantity);$this->assertSame('estimated',$offer->fresh()->stock_quantity_source);$this->assertDatabaseCount('offer_events',0);
    }
    public function test_reported_stock_to_confirmed_sold_out_uses_zero_not_a_reporting_mode_change(): void {
        $store=$this->store();$this->runImport($store,[$this->product(1,5)]);$this->runImport($store,[$this->product(1,null,false)]);
        $this->assertDatabaseHas('offer_events',['type'=>'possible_sale','stock_before'=>5,'stock_after'=>0,'units'=>5,'stock_evidence'=>'reported_to_zero']);
    }
    public function test_woocommerce_native_quantities_are_stored_and_decreases_sum_actual_units(): void {
        $store=$this->store();$store->update(['platform'=>'woocommerce']);
        foreach([5,3,3,0] as $quantity){
            $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn([
                ['id'=>1,'name'=>'Artist - Album CD','type'=>'simple','manage_stock'=>true,'stock_quantity'=>$quantity,'is_in_stock'=>$quantity>0,
                    'prices'=>['price'=>'10000','currency_code'=>'CLP','currency_minor_unit'=>0]]
            ]);$this->app->instance(StoreClient::class,$client);$run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('success',$run->fresh()->status);
        }
        $this->assertSame(0,$store->offers()->first()->stock_quantity);$this->assertEquals(5,DB::table('offer_events')->where('type','possible_sale')->sum('units'));
        $this->assertSame(2,DB::table('offer_events')->where('type','possible_sale')->count());
    }
    public function test_bsale_controlled_stock_is_measured_instead_of_defaulting_to_one(): void {
        $store=$this->store();$store->update(['platform'=>'bsale']);
        foreach([6,2,2] as $quantity){
            $item=['productId'=>1,'variantId'=>1,'title'=>'VINILO ARTIST/ ALBUM 1LP','link'=>'/product/album','finalPrice'=>10000,'totalStock'=>$quantity,'stockControl'=>1,'allowNegativeStock'=>0];
            $html='<script type="application/json" id="coll_data_base">'.json_encode(['items'=>[$item]]).'</script>';
            $empty='<script type="application/json" id="coll_data_base">{"items":[]}</script>';
            $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('html')->withArgs(fn($base,$path)=>$path==='/collection/vinilos')->andReturn($html);$client->shouldReceive('html')->withArgs(fn($base,$path)=>$path==='/collection/cds')->andReturn($empty);$this->app->instance(StoreClient::class,$client);
            $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('success',$run->fresh()->status);
        }
        $this->assertSame(2,$store->offers()->first()->stock_quantity);$this->assertDatabaseHas('offer_events',['type'=>'possible_sale','stock_before'=>6,'stock_after'=>2,'units'=>4,'stock_evidence'=>'reported_quantity']);
        $this->assertSame(1,DB::table('offer_events')->where('type','possible_sale')->count());
    }
    public function test_disappearance_sale_is_rolled_back_when_finalizing_the_scrape_fails(): void {
        $store=$this->store();$this->runImport($store,[$this->product(1,2)]);$offer=$store->offers()->firstOrFail();$this->travel(1)->minutes();
        $verifier=\Mockery::mock(StockAvailabilityVerifier::class);$verifier->shouldReceive('verify')->andReturnNull();$this->app->instance(StockAvailabilityVerifier::class,$verifier);
        $quantities=\Mockery::mock(StockQuantities::class)->makePartial();
        $quantities->shouldReceive('missingSales')->once()->andReturnUsing(function()use($store,$offer){
            DB::table('offer_events')->insert(['event_key'=>'test-rollback','offer_id'=>$offer->id,'store_id'=>$store->id,'type'=>'possible_sale','occurred_at'=>now(),'availability'=>'in_stock','units'=>1]);
            DB::table('offers')->where('id',$offer->id)->update(['stock_quantity'=>0]);throw new \RuntimeException('Finalización fallida');
        });$this->app->instance(StockQuantities::class,$quantities);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>[]]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('failed',$run->fresh()->status);$this->assertSame(0,DB::table('offer_events')->where('type','possible_sale')->count());$this->assertSame(2,$offer->fresh()->stock_quantity);
    }
}
