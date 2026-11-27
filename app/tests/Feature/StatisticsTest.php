<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,Offer,ScrapeRun,User};
use App\Services\{StoreClient,CatalogImporter,OfferEvents,CatalogStatistics};

class StatisticsTest extends TestCase {
    use RefreshDatabase;
    private function store(string $name='Tienda'): Store {
        return Store::create(['name'=>$name,'url'=>'https://'.uniqid().'.example','platform'=>'shopify','currency'=>'CLP','logo_checked_at'=>now()]);
    }
    private function product(int $id=10,bool $stock=true,?string $price='19990'): array {
        return ['title'=>'Artist - Album '.$id,'handle'=>'album-'.$id,'product_type'=>'CD','variants'=>[
            ['id'=>$id,'title'=>'Default Title','price'=>$price,'available'=>$stock]]];
    }
    private function import(Store $store,array $products): void {
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>$products]);
        $this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$store->refresh();
        $this->assertSame('success',$run->fresh()->status);
    }
    private function event(Store $store,string $title,string $type,string $date,array $extra=[]): Offer {
        $offer=Offer::create(['store_id'=>$store->id,'external_id'=>uniqid(),'title'=>$title,'format'=>$extra['format']??'cd',
            'price'=>10000,'currency'=>'CLP','availability'=>'in_stock','url'=>'https://example.com/product']);
        DB::table('offer_events')->insert($extra+['event_key'=>uniqid(),'store_id'=>$store->id,'offer_id'=>$offer->id,
            'type'=>$type,'occurred_at'=>$date,'price'=>10000,'currency'=>'CLP','availability'=>'in_stock','initial_load'=>false,'historical'=>false]);
        return $offer;
    }
    public function test_import_records_initial_load_new_offers_and_real_transitions_without_duplicates(): void {
        $this->travelTo(now()->setDate(2026,11,26)->setTime(10,0));$store=$this->store();$this->import($store,[$this->product()]);
        $this->assertDatabaseHas('offer_events',['type'=>'added','initial_load'=>true]);
        $this->import($store,[$this->product()]);$this->assertDatabaseCount('offer_events',1);
        $this->travel(1)->hours();$this->import($store,[$this->product(10,false,'18000'),$this->product(11)]);
        $this->assertDatabaseHas('offer_events',['type'=>'out_of_stock','previous_checked_at'=>'2026-11-26 10:00:00']);
        $this->assertDatabaseHas('offer_events',['type'=>'price_changed','previous_price'=>19990,'price'=>18000,'currency'=>'CLP']);
        $this->assertDatabaseHas('offer_events',['type'=>'added','initial_load'=>false]);$this->assertDatabaseCount('offer_events',5);
        $this->travel(1)->hours();$this->import($store,[$this->product(10,true,'18000'),$this->product(11)]);
        $this->assertDatabaseHas('offer_events',['type'=>'restocked']);$this->import($store,[$this->product(10,true,'18000'),$this->product(11)]);
        $this->assertDatabaseCount('offer_events',6);
    }
    public function test_missing_unknown_states_and_currency_changes_do_not_create_false_stock_or_price_events(): void {
        $store=$this->store();$this->import($store,[$this->product()]);$this->travel(1)->minutes();$this->import($store,[]);
        $this->assertNotNull(Offer::first()->missing_since);$this->assertDatabaseCount('offer_events',2);
        $this->import($store,[$this->product()]);$this->assertDatabaseCount('offer_events',2);
        $unknown=$this->product();unset($unknown['variants'][0]['available']);$this->import($store,[$unknown]);
        $this->import($store,[$this->product()]);$this->assertDatabaseCount('offer_events',2);
        $store->update(['currency'=>'USD']);$this->import($store,[$this->product(10,true,'20')]);$this->assertDatabaseCount('offer_events',2);
        $this->import($store,[$this->product(10,true,null)]);$this->assertDatabaseCount('offer_events',2);
    }
    public function test_old_sold_out_products_are_not_new_arrivals_and_only_known_stock_changes_are_movements(): void {
        $store=$this->store();$this->import($store,[$this->product(10)]);
        $this->import($store,[$this->product(10),$this->product(11,false)]);
        $this->assertDatabaseMissing('offers',['store_id'=>$store->id,'external_id'=>'11']);$this->assertDatabaseCount('offer_events',1);
        $this->import($store,[$this->product(10),$this->product(11,true)]);
        $new=Offer::where('external_id','11')->firstOrFail();$this->assertDatabaseHas('offer_events',['offer_id'=>$new->id,'type'=>'added','initial_load'=>false]);
        $this->import($store,[$this->product(10),$this->product(11,false)]);
        $this->import($store,[$this->product(10),$this->product(11,false)]);
        $this->assertDatabaseCount('offer_events',4);
        $counts=app(CatalogStatistics::class)->summary(['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()])['counts'];
        $this->assertSame(1,$counts['added']);$this->assertSame(1,$counts['out_of_stock']);$this->assertArrayNotHasKey('restocked',$counts->all());
        $this->actingAs(User::factory()->create())->get(route('statistics.index'))->assertOk()->assertSee('Vendidos')->assertSee('Posibles copias vendidas');
    }
    public function test_first_observation_without_stock_is_only_a_baseline_and_later_restock_is_counted(): void {
        $store=$this->store();$offer=Offer::create(['store_id'=>$store->id,'external_id'=>'baseline','title'=>'Artist - Album','format'=>'cd','price'=>10000,'currency'=>'CLP','availability'=>'out_of_stock','url'=>'https://example.com/product']);
        $current=['price'=>10000,'currency'=>'CLP','availability'=>'out_of_stock','created_at'=>now()];
        app(OfferEvents::class)->record($offer,null,$current,null,false);
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'added','initial_load'=>true]);
        $counts=app(CatalogStatistics::class)->summary(['store_id'=>$store->id,'from'=>now()->toDateString(),'to'=>now()->toDateString()])['counts'];$this->assertCount(0,$counts);
        $previous=$current;$current['availability']='in_stock';$current['created_at']=now()->addMinute();
        $id=DB::table('offer_observations')->insertGetId(['offer_id'=>$offer->id]+$current);
        app(OfferEvents::class)->record($offer,$previous,$current,$id,false);
        $this->assertDatabaseHas('offer_events',['offer_id'=>$offer->id,'type'=>'restocked','previous_availability'=>'out_of_stock']);
        $this->assertDatabaseMissing('offer_events',['offer_id'=>$offer->id,'type'=>'out_of_stock']);
    }
    public function test_history_backfill_is_idempotent_and_does_not_invent_first_stock_transitions(): void {
        $store=$this->store();$offer=Offer::create(['store_id'=>$store->id,'external_id'=>'legacy','title'=>'Old album','format'=>'cd',
            'price'=>18000,'currency'=>'CLP','availability'=>'in_stock','url'=>'https://example.com','created_at'=>'2026-11-25 09:00:00']);
        ScrapeRun::create(['store_id'=>$store->id,'status'=>'success','finished_at'=>'2026-11-25 10:00:00']);
        foreach([['2026-11-25 09:00:00','in_stock',20000],['2026-11-26 09:00:00','out_of_stock',18000],['2026-11-26 10:00:00','in_stock',18000]] as [$date,$stock,$price])
            DB::table('offer_observations')->insert(['offer_id'=>$offer->id,'created_at'=>$date,'availability'=>$stock,'price'=>$price]);
        app(OfferEvents::class)->backfill();app(OfferEvents::class)->backfill();
        $this->assertDatabaseCount('offer_events',4);$this->assertDatabaseHas('offer_events',['type'=>'added','initial_load'=>true,'historical'=>true]);
        $this->assertDatabaseHas('offer_events',['type'=>'price_changed','currency'=>null,'previous_price'=>20000,'price'=>18000]);
        $this->assertDatabaseHas('offer_events',['type'=>'out_of_stock','occurred_at'=>'2026-11-26 09:00:00']);
    }
    public function test_filters_apply_inclusive_local_dates_store_and_format_and_initial_load_is_opt_in(): void {
        $store=$this->store();$other=$this->store('Otra');
        $this->event($store,'Inside first','added','2026-11-26 00:00:00');$this->event($store,'Inside last','restocked','2026-11-26 23:59:59');
        $this->event($store,'Outside date','added','2026-11-27 00:00:00');$this->event($other,'Outside store','added','2026-11-26 12:00:00');
        $vinyl=$this->event($store,'Outside format','added','2026-11-26 12:00:00');$vinyl->update(['format'=>'vinyl']);
        $this->event($store,'Initial load item','added','2026-11-26 12:00:00',['initial_load'=>true]);
        $this->actingAs(User::factory()->create());$filters=['from'=>'2026-11-26','to'=>'2026-11-26','store_id'=>$store->id,'format'=>'cd'];
        $this->get(route('statistics.index',$filters))->assertOk()->assertSee('Inside first')->assertSee('Inside last')
            ->assertDontSee('Outside date')->assertDontSee('Outside store')->assertDontSee('Outside format')->assertDontSee('Initial load item')
            ->assertViewHas('counts',fn($counts)=>$counts['added']===1 && $counts['restocked']===1);
        $this->get(route('statistics.index',$filters+['include_initial'=>1]))->assertOk()->assertSee('Initial load item');
    }
    public function test_csv_exports_all_filtered_rows_not_only_page_and_neutralizes_formulas(): void {
        $store=$this->store();$this->actingAs(User::factory()->create());
        for($i=0;$i<35;$i++)$this->event($store,'Export item '.$i,'added','2026-11-26 12:00:00');
        $this->event($store,'=HYPERLINK("bad")','added','2026-11-26 12:00:00');$this->event($store,'Not exported','restocked','2026-11-26 12:00:00');
        $filters=['from'=>'2026-11-26','to'=>'2026-11-26','store_id'=>$store->id,'format'=>'cd','type'=>'added'];
        $response=$this->get(route('statistics.export',$filters))->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $csv=$response->streamedContent();$this->assertStringStartsWith("\xEF\xBB\xBF",$csv);
        $lines=explode("\n",trim(substr($csv,3)));$this->assertCount(37,$lines);
        $this->assertStringContainsString('Export item 34',$csv);$this->assertStringContainsString("'=HYPERLINK",$csv);$this->assertStringNotContainsString('Not exported',$csv);
    }
    public function test_statistics_and_export_are_private_and_invalid_filters_are_rejected(): void {
        $this->get(route('statistics.index'))->assertRedirect('/login');$this->get(route('statistics.export'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('statistics.index'))->assertOk()->assertSee('No hay movimientos');
        $this->get(route('statistics.index',['from'=>'2026-11-27','to'=>'2026-11-26']))->assertSessionHasErrors('to');
        $this->get(route('statistics.export',['format'=>'dvd']))->assertSessionHasErrors('format');
        $this->get(route('statistics.export',['store_id'=>999]))->assertSessionHasErrors('store_id');
    }
}
