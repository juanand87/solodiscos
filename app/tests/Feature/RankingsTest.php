<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,Album,Offer,User};
use App\Services\{StoreRankings,PriceHistory};

class RankingsTest extends TestCase {
    use RefreshDatabase;
    private function store(string $name): Store {return Store::create(['name'=>$name,'url'=>'https://'.uniqid().'.example','platform'=>'woocommerce','currency'=>'CLP','active'=>true]);}
    private function offer(Store $store,int $album,float $price=30000,array $extra=[]): Offer {
        $record=Album::firstOrCreate(['match_key'=>hash('sha256','album-'.$album)],['artist'=>'Artist','title'=>'Album '.$album]);
        return Offer::create($extra+['store_id'=>$store->id,'album_id'=>$record->id,'external_id'=>uniqid(),'title'=>'Artist - Album '.$album,
            'format'=>'cd','condition'=>'new','currency'=>'CLP','price'=>$price,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://example.com/product']);
    }
    private function rankings(array $filters=[]): array {return app(StoreRankings::class)->calculate($filters+['currency'=>'CLP']);}
    public function test_batches_preserve_cross_batch_minimum_prices_and_feed_count_and_order(): void {
        $a=$this->store('A');$b=$this->store('B');$deals=[];
        for($i=1;$i<=510;$i++){
            $offer=$this->offer($a,$i,100);$this->offer($b,$i,200);
            if($i<=60){
                $started=now()->subMinutes($i%7)->format('Y-m-d H:i:s');
                DB::table('offer_price_tracking')->insert(['offer_id'=>$offer->id,'identity_key'=>PriceHistory::identity($offer),
                    'last_price'=>100,'last_checked_at'=>$offer->checked_at,'last_available'=>true,'status'=>'offer','reference_price'=>200,'started_at'=>$started]);
                $deals[]=['id'=>$offer->id,'started'=>$started];
            }
        }
        $this->offer($b,1,50); // The cheapest publication occurs after the first 1,000 records.
        $result=$this->rankings();$rows=collect($result['recommendations'])->keyBy('store.id');
        $this->assertSame(1021,$result['offer_count']);$this->assertSame(510,$rows[$a->id]['sample']);
        $this->assertSame(509,$rows[$a->id]['cheaper_products']);$this->assertSame(1,$rows[$b->id]['cheaper_products']);
        $this->assertSame(60,$result['deal_count']);$this->assertCount(24,$result['feed']);
        usort($deals,fn($x,$y)=>strcmp($y['started'],$x['started'])?:($y['id']<=>$x['id']));
        $this->assertSame(array_column(array_slice($deals,0,24),'id'),array_column($result['feed'],'id'));
        $this->assertSame(60,collect($result['discounts'])->firstWhere('store.id',$a->id)['sample']);
    }
    public function test_cheaper_products_includes_ties_deduplicates_stores_and_respects_filters(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');
        foreach(range(1,100) as $album){$this->offer($a,$album,100);$this->offer($b,$album,200);$this->offer($c,$album,300);}
        $this->offer($a,1,50); // Two publications in one store still count once.
        $this->offer($b,2,100); // A tie counts for both stores.
        $this->offer($b,3,90); // Cheaper than one competitor is insufficient for A.
        foreach([['format'=>'vinyl'],['condition'=>'used'],['currency'=>'USD']] as $extra){$this->offer($a,101,100,$extra);$this->offer($b,101,200,$extra);}
        $rows=collect($this->rankings()['recommendations'])->keyBy('store.id');
        $this->assertSame(101,$rows[$a->id]['cheaper_products']); // 99 CD new + vinyl new + CD used.
        $this->assertSame(2,$rows[$b->id]['cheaper_products']);$this->assertSame(0,$rows[$c->id]['cheaper_products']);
        foreach([['format'=>'cd','condition'=>'new'],['format'=>'vinyl'],['condition'=>'used'],['currency'=>'USD']] as $i=>$filters){
            $row=collect($this->rankings($filters)['recommendations'])->firstWhere('store.id',$a->id);
            $this->assertSame($i===0?99:1,$row['cheaper_products']);
        }
        $this->get(route('rankings'))->assertOk()->assertSee('Productos más baratos que otras tiendas')->assertSee('Incluye empates');
        $this->actingAs(User::factory()->create())->get(route('admin.rankings'))->assertOk()->assertSee('Productos más baratos que otras tiendas');
    }
    private function check(Offer $offer,float $price,?string $stock=null): void {
        $offer->update(['price'=>$price,'checked_at'=>now()]+($stock?['availability'=>$stock]:[]));app(PriceHistory::class)->record($offer->fresh());
    }
    private function history(Offer $offer): void {
        $end=now()->copy();
        for($days=32;$days>=2;$days-=2){$this->travelTo($end->copy()->subDays($days));$this->check($offer,30000);}
        $this->travelTo($end);
    }
    public function test_cheapest_uses_other_store_median_one_offer_per_album_and_thirty_shared_albums(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');
        for($i=1;$i<=100;$i++){ $this->offer($a,$i,100);$this->offer($b,$i,200);$this->offer($c,$i,300); }
        $this->offer($a,1,1000);$result=$this->rankings();$rows=$result['cheap']['new'];
        $this->assertSame($a->id,$rows[0]['store']->id);$this->assertTrue($rows[0]['eligible']);$this->assertSame(100,$rows[0]['sample']);
        $this->assertEqualsWithDelta(40,$rows[0]['index'],.001);$this->assertEqualsWithDelta(100,$rows[1]['index'],.001);
        $this->assertEqualsWithDelta(200,$rows[2]['index'],.001);$this->assertSame(100,$rows[0]['wins']);
        $variety=collect($result['variety'])->firstWhere('store.id',$a->id);$this->assertSame(100,$variety['total']);$this->assertSame(101,$variety['publications']);
    }
    public function test_condition_currency_freshness_and_stock_are_not_mixed_to_reach_sample_threshold(): void {
        $stores=[$this->store('A'),$this->store('B'),$this->store('C')];
        foreach($stores as $store)for($i=1;$i<=29;$i++)$this->offer($store,$i,100);
        $this->offer($stores[0],30,100);$this->offer($stores[1],30,100,['condition'=>'used']);$this->offer($stores[2],30,100,['condition'=>'unknown']);
        foreach($stores as $store){
            $this->offer($store,31,1,['currency'=>'USD']);$this->offer($store,32,1,['availability'=>'out_of_stock']);
            $this->offer($store,33,1,['checked_at'=>now()->subHours(73)]);$this->offer($store,34,1,['missing_since'=>now()]);
            $this->offer($store,35,1,['excluded_reason'=>'Libro']);
        }
        $rows=$this->rankings()['cheap']['new'];foreach($rows as $row){$this->assertFalse($row['eligible']);$this->assertSame(29,$row['sample']);}
        $result=$this->rankings(['condition'=>'used']);$this->assertSame(1,$result['offer_count']);
        $this->assertSame(3,$this->rankings(['currency'=>'USD'])['offer_count']);
        $stores[0]->update(['active'=>false]);$this->assertSame(2,$this->rankings()['store_count']);
    }
    public function test_variety_counts_album_format_once_even_with_multiple_conditions_and_publications(): void {
        $store=$this->store('Variedad');$this->offer($store,1);$this->offer($store,1,20000,['condition'=>'used']);
        $this->offer($store,1,30000,['format'=>'vinyl']);$this->offer($store,2,30000,['condition'=>'unknown']);
        $row=$this->rankings()['variety'][0];$this->assertSame(3,$row['total']);$this->assertSame(2,$row['cd']);$this->assertSame(1,$row['vinyl']);$this->assertSame(4,$row['publications']);
        $this->assertSame(1,$this->rankings(['format'=>'vinyl'])['variety'][0]['total']);
    }
    public function test_drop_without_history_is_not_a_verified_offer_and_disappears_on_price_increase(): void {
        $store=$this->store('Bajadas');$offer=$this->offer($store,1);$this->check($offer,30000);
        $this->travel(2)->days();$this->check($offer,24000);$badge=app(PriceHistory::class)->badge($offer->fresh());
        $this->assertFalse($badge['verified']);$this->assertEqualsWithDelta(20,$badge['percent'],.001);$this->assertSame(0,$this->rankings()['discounts'][0]['sample']);
        $this->get(route('rankings.statistics'))->assertOk()->assertSee('Bajada detectada')->assertSee('Bajadas');
        $this->get(route('album',[$offer->album_id,'cd']))->assertOk()->assertSee('Bajada detectada');
        $this->travel(1)->days();$this->check($offer,25000);
        $this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));$this->assertSame(0,$this->rankings()['deal_count']);
        $this->get(route('rankings.statistics'))->assertOk()->assertSee('El historial empieza aquí.');
        $this->assertDatabaseHas('offer_price_tracking',['offer_id'=>$offer->id,'status'=>'closed','close_reason'=>'price_increased']);
    }
    public function test_verified_offer_keeps_reference_on_further_drop_and_rebound_cannot_reuse_old_discount(): void {
        $this->travelTo(now()->setDate(2026,12,31)->setTime(12,0));$offer=$this->offer($this->store('Historia'),1);$this->history($offer);
        $this->check($offer,24000);$badge=app(PriceHistory::class)->badge($offer->fresh());$this->assertTrue($badge['verified']);$this->assertSame(30000.0,$badge['reference']);
        $this->travel(1)->days();$this->check($offer,22000);$this->assertSame(30000.0,app(PriceHistory::class)->badge($offer->fresh())['reference']);
        $this->travel(1)->days();$this->check($offer,25000);$this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));
        $this->travel(1)->days();$this->check($offer,24000);$badge=app(PriceHistory::class)->badge($offer->fresh());$this->assertFalse($badge['verified']);$this->assertSame(25000.0,$badge['reference']);
        $this->travel(1)->days();$this->check($offer,26000);$this->travel(1)->days();$this->check($offer,21000);
        $badge=app(PriceHistory::class)->badge($offer->fresh());$this->assertTrue($badge['verified']);$this->assertSame(22000.0,$badge['reference']);
    }
    public function test_gap_stock_change_and_identity_change_close_deals_and_do_not_reactivate_automatically(): void {
        $offer=$this->offer($this->store('Seguimiento'),1);$this->check($offer,30000);$this->travel(1)->days();$this->check($offer,24000);
        $this->travel(4)->days();$this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));$this->check($offer,23000);
        $this->assertDatabaseHas('offer_price_tracking',['offer_id'=>$offer->id,'close_reason'=>'tracking_gap']);
        $this->travel(1)->days();$this->check($offer,22000);$this->assertNotNull(app(PriceHistory::class)->badge($offer->fresh()));
        $this->check($offer,22000,'out_of_stock');$this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));
        $this->travel(1)->days();$this->check($offer,22000,'in_stock');$this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));
        $this->travel(1)->days();$this->check($offer,20000);$offer->update(['currency'=>'USD']);$this->check($offer,10);
        $this->assertDatabaseHas('offer_price_tracking',['offer_id'=>$offer->id,'close_reason'=>'identity_changed']);$this->assertNull(app(PriceHistory::class)->badge($offer->fresh()));
    }
    public function test_daily_checks_are_recorded_even_without_price_changes_and_seed_is_idempotent(): void {
        $offer=$this->offer($this->store('Constante'),1);$history=app(PriceHistory::class);$history->seed();$history->seed();
        $this->assertDatabaseCount('offer_price_days',1);$this->assertDatabaseCount('offer_price_tracking',1);
        $this->travel(2)->days();$this->check($offer,30000);$this->assertDatabaseCount('offer_price_days',2);
        $this->check($offer,30000);$this->assertDatabaseCount('offer_price_days',2);
    }
    public function test_discount_ranking_requires_ten_distinct_albums_and_deduplicates_variants(): void {
        $a=$this->store('Diez');$b=$this->store('Nueve');
        foreach([[$a,10,20000],[$b,9,100000]] as [$store,$count,$reference])for($i=1;$i<=$count;$i++){
            $offer=$this->offer($store,$i,10000);$this->check($offer,10000);
            DB::table('offer_price_tracking')->where('offer_id',$offer->id)->update(['status'=>'offer','reference_price'=>$reference,
                'reference_from'=>now()->subDays(30),'reference_to'=>now(),'started_at'=>now()]);
        }
        $this->offer($a,1,15000);$result=$this->rankings();$rows=$result['discounts'];
        $this->assertSame($a->id,$rows[0]['store']->id);$this->assertTrue($rows[0]['eligible']);$this->assertSame(10,$rows[0]['sample']);
        $this->assertSame(50.0,$rows[0]['percent']);$this->assertSame(100,$rows[0]['catalog_percent']);$this->assertFalse($rows[1]['eligible']);
    }
    public function test_public_and_private_rankings_render_and_reject_invalid_filters(): void {
        $this->get(route('rankings'))->assertOk()->assertSee('Top 10 tiendas recomendadas')->assertSee('Otras tiendas recomendadas')->assertDontSee('Tiendas con más productos');
        $this->get(route('admin.rankings'))->assertRedirect('/login');$this->actingAs(User::factory()->create());
        $this->get(route('admin.rankings'))->assertOk()->assertSee('Control del ranking');
        $this->get(route('rankings',['format'=>'dvd']))->assertSessionHasErrors('format');
        $this->get(route('rankings',['currency'=>'BTC']))->assertSessionHasErrors('currency');
    }
    public function test_a_brief_price_spike_does_not_become_a_verified_historical_offer(): void {
        $this->travelTo(now()->setDate(2026,12,31)->setTime(12,0));$offer=$this->offer($this->store('Referencia'),1);$this->history($offer);
        $this->travel(-1)->days();$this->check($offer,50000);$this->travel(1)->days();$this->check($offer,30000);
        $badge=app(PriceHistory::class)->badge($offer->fresh());$this->assertNotNull($badge);$this->assertFalse($badge['verified']);
        $this->assertSame(0,$this->rankings()['discounts'][0]['sample']);
    }
    public function test_sparse_monthly_checks_cannot_qualify_as_complete_price_history(): void {
        $end=now()->copy();$offer=$this->offer($this->store('Esporádica'),1);
        foreach([32,20,2] as $days){$this->travelTo($end->copy()->subDays($days));$this->check($offer,30000);}
        $this->travelTo($end);$this->check($offer,20000);$this->assertFalse(app(PriceHistory::class)->badge($offer->fresh())['verified']);
    }
    public function test_equal_prices_count_all_ties_and_equal_indexes_prioritize_larger_samples(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');$d=$this->store('D');
        foreach([$a,$b,$c] as $store)for($i=1;$i<=30;$i++)$this->offer($store,$i,10000);
        foreach([$a,$b,$d] as $store)$this->offer($store,31,10000);
        $rows=$this->rankings()['cheap']['new'];
        $this->assertSame([$a->id,$b->id,$c->id,$d->id],array_column(array_column($rows,'store'),'id'));
        foreach($rows as $row){$this->assertSame(100.0,$row['index']);$this->assertEquals(100,$row['wins_percent']);$this->assertSame($row['sample'],$row['wins']);}
        $this->assertSame(31,$rows[0]['sample']);$this->assertSame(30,$rows[2]['sample']);$this->assertFalse($rows[3]['eligible']);
    }
    public function test_active_stores_without_eligible_products_receive_no_displayed_position(): void {
        $store=$this->store('Sin muestra');$this->offer($store,1,10000,['availability'=>'out_of_stock']);
        $response=$this->get(route('rankings'))->assertOk()->assertSee('Todavía no hay tiendas con 100 productos comparables');
        $this->assertStringNotContainsString('<span class="ranking-position">',$response->getContent());
        $this->assertSame(0,$this->rankings()['variety'][0]['total']);
    }
    public function test_two_stores_are_sufficient_and_a_single_store_product_is_not_comparable(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');
        for($i=1;$i<=100;$i++){$this->offer($a,$i,10000);$this->offer($b,$i,20000);}
        $this->offer($a,1,15000);$this->offer($c,200,5000);
        $rows=collect($this->rankings()['cheap']['new'])->keyBy('store.id');
        $this->assertTrue($rows[$a->id]['eligible']);$this->assertSame(100,$rows[$a->id]['sample']);$this->assertSame(50.0,$rows[$a->id]['index']);
        $this->assertSame(200.0,$rows[$b->id]['index']);$this->assertSame(1,$rows[$a->id]['competitor_count']);
        $this->assertTrue($rows[$a->id]['concentrated']);$this->assertEquals(100,$rows[$a->id]['solo_share']);
        $this->assertSame($b->id,$rows[$a->id]['dominant_competitor']->id);$this->assertSame(0,$rows[$c->id]['sample']);
        $this->get(route('rankings'))->assertOk()->assertSee('Productos recomendados');
    }
    public function test_concentration_counts_only_exclusive_comparisons_not_shared_competitor_presence(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');
        for($i=1;$i<=30;$i++){$this->offer($a,$i);$this->offer($b,$i);if($i<=10)$this->offer($c,$i);}
        $rows=collect($this->rankings()['cheap']['new'])->keyBy('store.id');
        $this->assertSame(2,$rows[$a->id]['competitor_count']);$this->assertSame(20,$rows[$a->id]['solo_comparisons']);
        $this->assertEqualsWithDelta(66.6667,$rows[$a->id]['solo_share'],.001);$this->assertTrue($rows[$a->id]['concentrated']);
        $this->assertSame(2,$rows[$c->id]['competitor_count']);$this->assertFalse($rows[$c->id]['concentrated']);
        for($i=11;$i<=15;$i++)$this->offer($c,$i);
        $rows=collect($this->rankings()['cheap']['new'])->keyBy('store.id');
        $this->assertEquals(50,$rows[$a->id]['solo_share']);$this->assertFalse($rows[$a->id]['concentrated']);
    }
}
