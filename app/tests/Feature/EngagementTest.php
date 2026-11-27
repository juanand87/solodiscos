<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth,DB,Schema};
use App\Models\{Album,Offer,Store,StoreAccount,User};
use App\Services\EngagementReports;

class EngagementTest extends TestCase {
    use RefreshDatabase;
    private function store(string $name): Store {return Store::create(['name'=>$name,'url'=>'https://'.$name.'.example','platform'=>'shopify','currency'=>'CLP','active'=>true]);}
    private function offer(Store $store,?Album $album=null): Offer {
        $album??=Album::create(['artist'=>'Artist','title'=>'Album '.$store->id,'match_key'=>hash('sha256','album-'.$store->id)]);
        return Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>'offer-'.Offer::count(),'title'=>$store->name.' disc','price'=>10000,'currency'=>'CLP','format'=>'cd','condition'=>'new','availability'=>'in_stock','checked_at'=>now(),'url'=>$store->url.'/product']);
    }
    private function account(Store $store): StoreAccount {$a=new StoreAccount(['name'=>'Manager','email'=>'manager@'.parse_url($store->url,PHP_URL_HOST),'password'=>'ExamplePassword123']);$a->store_id=$store->id;$a->email_verified_at=now();$a->save();return $a;}
    private function human(): void {$this->withHeader('User-Agent','Mozilla/5.0 Browser');}
    private function metric(string $kind,Offer $offer,int $total,string $day=null): void {
        $day??=now()->toDateString();DB::table('engagement_days')->insert(['metric_key'=>hash('sha256',$kind.'-'.$offer->id.'-'.$day),'day'=>$day,'kind'=>$kind,'album_id'=>$offer->album_id,'format'=>$offer->format,'store_id'=>$kind==='album_view'?null:$offer->store_id,'offer_id'=>$kind==='album_view'?null:$offer->id,'total'=>$total]);
    }
    public function test_album_views_count_once_and_store_exposures_do_not_multiply_general_views(): void {
        $this->human();$one=$this->offer($this->store('one'));$two=$this->offer($this->store('two'),$one->album);
        $url=route('album',[$one->album_id,'cd']);$first=$this->get($url)->assertOk();$this->withCookie(config('session.cookie'),$first->getCookie(config('session.cookie'))->getValue());$this->get($url)->assertOk();
        $this->assertEquals(1,DB::table('engagement_days')->where('kind','album_view')->sum('total'));$this->assertEquals(2,DB::table('engagement_days')->where('kind','product_view')->sum('total'));
        $this->travel(31)->minutes();$this->get($url)->assertOk();$this->assertEquals(2,DB::table('engagement_days')->where('kind','album_view')->sum('total'));$this->travelBack();
        $columns=Schema::getColumnListing('engagement_days');foreach(['ip','ip_address','user_agent','visitor_id','session_id'] as $column)$this->assertNotContains($column,$columns);
    }
    public function test_outbound_clicks_are_deduplicated_and_redirect_only_to_the_bound_product(): void {
        $this->human();$offer=$this->offer($this->store('one'));$url=route('offers.visit',$offer);
        $first=$this->get($url.'?url=https://evil.example')->assertRedirect($offer->url)->assertHeader('Cache-Control','no-store, private');$this->withCookie(config('session.cookie'),$first->getCookie(config('session.cookie'))->getValue());$this->get($url)->assertRedirect($offer->url);
        $this->assertEquals(1,DB::table('engagement_days')->where('kind','click')->sum('total'));$this->travel(61)->seconds();$this->get($url)->assertRedirect($offer->url);$this->assertEquals(2,DB::table('engagement_days')->where('kind','click')->sum('total'));$this->travelBack();
        $offer->update(['url'=>'https://evil.example/product']);$this->get($url)->assertNotFound();$offer->update(['url'=>'javascript:alert(1)']);$this->get($url)->assertNotFound();
    }
    public function test_woocommerce_redirect_tags_the_origin_and_preserves_variation_and_fragment(): void {
        $store=$this->store('woo');$store->update(['platform'=>'woocommerce']);$offer=$this->offer($store);
        $offer->update(['url'=>$store->url.'/product?attribute_pa_color=azul&variation_id=123&utm_source=old&utm_medium=email#detalles']);
        $response=$this->get(route('offers.visit',$offer).'?utm_source=attacker')->assertRedirect();
        $parts=parse_url($response->headers->get('Location'));parse_str($parts['query'],$query);
        $this->assertSame('woo.example',$parts['host']);$this->assertSame('/product',$parts['path']);$this->assertSame('detalles',$parts['fragment']);
        $this->assertSame(['attribute_pa_color'=>'azul','variation_id'=>'123','utm_source'=>'solodiscos.cl','utm_medium'=>'referral','utm_campaign'=>'catalogo'],$query);
        $this->assertSame($store->url.'/product?attribute_pa_color=azul&variation_id=123&utm_source=old&utm_medium=email#detalles',$offer->fresh()->url);
    }
    public function test_bots_prefetch_admin_and_store_sessions_do_not_count(): void {
        $store=$this->store('one');$offer=$this->offer($store);$url=route('album',[$offer->album_id,'cd']);
        $this->withHeader('User-Agent','Googlebot')->get($url)->assertOk();$this->human();$this->withHeader('Sec-Purpose','prefetch')->get(route('offers.visit',$offer))->assertRedirect($offer->url);
        $this->withHeader('Sec-Purpose','');$this->actingAs(User::factory()->create(),'web')->get($url)->assertOk();Auth::guard('web')->logout();
        $this->actingAs($this->account($store),'store')->get(route('offers.visit',$offer))->assertRedirect($offer->url);$this->assertDatabaseCount('engagement_days',0);
    }
    public function test_failure_to_store_metrics_does_not_break_the_catalog_or_redirect(): void {
        $this->human();$offer=$this->offer($this->store('one'));Schema::drop('engagement_days');
        $this->get(route('album',[$offer->album_id,'cd']))->assertOk();$this->get(route('offers.visit',$offer))->assertRedirect($offer->url);
    }
    public function test_reports_apply_store_date_format_filters_and_do_not_show_foreign_clicks(): void {
        $store=$this->store('one');$own=$this->offer($store);$other=$this->offer($this->store('two'));
        $this->metric('album_view',$own,9);$this->metric('product_view',$own,7);$this->metric('click',$own,3);$this->metric('product_view',$other,20);$this->metric('click',$other,99);
        $f=['from'=>now()->toDateString(),'to'=>now()->toDateString(),'store_id'=>$store->id];$report=app(EngagementReports::class)->report($f);$this->assertSame(7,$report['views']);$this->assertSame(3,$report['clicks']);$this->assertSame($own->id,$report['topClicks']->first()->offer_id);
        $this->assertSame(0,app(EngagementReports::class)->report($f+['format'=>'vinyl'])['clicks']);
        $this->actingAs($this->account($store),'store')->get(route('portal.statistics',['store_id'=>$other->store_id]))->assertOk()->assertDontSee($other->title);
        $this->actingAs(User::factory()->create(),'web')->get(route('statistics.index'))->assertOk()->assertSee('Clics y consultas por tienda');
        $global=app(EngagementReports::class)->report(array_diff_key($f,['store_id'=>true]));$this->assertSame(9,$global['views']);$this->assertSame(102,$global['clicks']);
        $past=app(EngagementReports::class)->report(['from'=>now()->subDays(10)->toDateString(),'to'=>now()->subDays(5)->toDateString()]);$this->assertSame(0,$past['clicks']);$this->assertSame([],$past['series']);
    }
    public function test_scarcity_counts_stores_not_listings_and_restock_requires_interest_and_no_own_stock(): void {
        $store=$this->store('one');$own=$this->offer($store);$own->update(['availability'=>'out_of_stock']);$other=$this->offer($this->store('two'),$own->album);$this->offer($other->store,$own->album);
        $stale=$this->offer($this->store('stale'),$own->album);$stale->update(['checked_at'=>now()->subDays(4)]);
        $this->metric('album_view',$other,4);$f=['from'=>now()->toDateString(),'to'=>now()->toDateString(),'store_id'=>$store->id];$report=app(EngagementReports::class)->report($f);
        $this->assertCount(1,$report['scarce']);$this->assertEquals(1,$report['scarce']->first()->stores);$this->assertCount(1,$report['replenish']);$this->assertEquals(4,$report['replenish']->first()->views);
        $this->offer($store,$own->album);$this->assertCount(0,app(EngagementReports::class)->report($f)['replenish']);
        $this->assertCount(0,app(EngagementReports::class)->report($f+['format'=>'vinyl'])['scarce']);
    }
    public function test_public_buttons_use_tracking_routes_but_staff_source_links_remain_direct(): void {
        $store=$this->store('one');$offer=$this->offer($store);$this->get(route('album',[$offer->album_id,'cd']))->assertOk()->assertSee(route('offers.visit',$offer));
        $this->actingAs($this->account($store),'store')->get(route('portal.catalog'))->assertOk()->assertSee($offer->url);
    }
    public function test_daily_graph_fills_only_measured_days_and_keeps_exact_totals(): void {
        $offer=$this->offer($this->store('one'));DB::table('engagement_state')->where('id',1)->update(['started_at'=>now()->subDays(2)]);
        $this->metric('album_view',$offer,8,now()->subDay()->toDateString());$this->metric('click',$offer,2);
        $report=app(EngagementReports::class)->report(['from'=>now()->subDays(10)->toDateString(),'to'=>now()->toDateString()]);
        $this->assertCount(3,$report['series']);$this->assertSame([0,8,0],array_column($report['series'],'views'));$this->assertSame([0,0,2],array_column($report['series'],'clicks'));
        $this->assertSame(8,$report['views']);$this->assertSame(2,$report['clicks']);
        $this->actingAs(User::factory()->create(),'web')->get(route('statistics.index'))->assertOk()->assertSee('engagement-bar-views')->assertSee('Ver valores diarios');
    }
    public function test_private_disc_modal_scopes_six_calendar_months_and_rejects_foreign_offers(): void {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-27 15:00:00'));
        $store=$this->store('one');$own=$this->offer($store);$own->update(['image'=>'https://one.example/cover.jpg']);
        $foreign=$this->offer($this->store('two'),$own->album);
        DB::table('engagement_state')->where('id',1)->update(['started_at'=>'2026-07-15 10:00:00']);
        $this->metric('click',$own,3,'2026-07-20');$this->metric('click',$own,2,'2026-11-27');$this->metric('click',$own,99,'2026-05-31');$this->metric('click',$foreign,40,'2026-11-27');
        $url=route('portal.statistics.disc',[$own->album_id,'cd']);$admin=route('statistics.disc',[$own->album_id,'cd']);
        $this->getJson($admin)->assertUnauthorized();$this->get($url)->assertRedirect(route('portal.login'));
        $this->actingAs($this->account($store),'store')->getJson($url.'?store_id='.$foreign->store_id)->assertOk()->assertJsonPath('total',5)->assertJsonCount(6,'months')->assertJsonPath('months.0.month','2026-06')->assertJsonPath('months.0.measured',false)->assertJsonPath('months.1.clicks',3)->assertJsonPath('months.5.clicks',2)->assertJsonPath('image','https://one.example/cover.jpg');
        $this->getJson($url.'?offer_id='.$foreign->id)->assertNotFound();
        $this->getJson(route('portal.statistics.disc',[$own->album_id,'vinyl']))->assertNotFound();
        $this->getJson($admin)->assertUnauthorized();
        $this->actingAs(User::factory()->create(),'web')->getJson($admin)->assertOk()->assertJsonPath('total',45);
        $this->getJson($admin.'?store_id='.$store->id.'&offer_id='.$own->id)->assertOk()->assertJsonPath('total',5)->assertHeader('Cache-Control','no-store, private');
        $this->get(route('statistics.index'))->assertOk()->assertSee('data-disc-detail',false)->assertSee('data-disc-modal',false)->assertSee('Resumen por tienda')->assertSee('Todos los movimientos');
        $this->travelBack();
    }
    public function test_restock_can_use_existing_possible_sales_without_any_traffic_measurement(): void {
        $store=$this->store('one');$own=$this->offer($store);$own->update(['availability'=>'out_of_stock']);$other=$this->offer($this->store('two'),$own->album);
        DB::table('offer_events')->insert(['event_key'=>'restock-sale','offer_id'=>$other->id,'store_id'=>$other->store_id,'type'=>'possible_sale','occurred_at'=>now(),'availability'=>'in_stock','initial_load'=>false,'historical'=>false,'units'=>2]);
        $f=['from'=>now()->toDateString(),'to'=>now()->toDateString(),'store_id'=>$store->id];$report=app(EngagementReports::class)->report($f);
        $this->assertCount(1,$report['replenish']);$this->assertEquals(2,$report['replenish']->first()->units);$this->assertEquals(0,$report['replenish']->first()->views);
    }
}
