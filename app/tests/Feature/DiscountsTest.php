<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Album,Offer,Store};
use App\Services\InitialPriceDiscounts;

class DiscountsTest extends TestCase {
    use RefreshDatabase;
    private function offer(string $storeName='one',int $price=30000): Offer {
        $store=Store::firstOrCreate(['name'=>$storeName],['url'=>'https://'.$storeName.'.example','platform'=>'shopify','active'=>true,'currency'=>'CLP']);
        $album=Album::create(['artist'=>'Artist','title'=>'Album '.Album::count(),'match_key'=>hash('sha256','discount-'.Album::count())]);
        return Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>(string)$album->id,'title'=>'Artist - '.$album->title,'price'=>$price,'currency'=>'CLP','format'=>'cd','condition'=>'new','availability'=>'in_stock','checked_at'=>now(),'image'=>'https://'.$storeName.'.example/cover.jpg','url'=>$store->url.'/product']);
    }
    private function observe(Offer $offer,$price,$at,string $currency=null): void {DB::table('offer_observations')->insert(['offer_id'=>$offer->id,'price'=>$price,'availability'=>'in_stock','currency'=>$currency,'created_at'=>$at]);}
    public function test_discounts_use_first_valid_known_currency_price_and_exact_percentage(): void {
        $offer=$this->offer();$this->observe($offer,99999,now()->subDays(10));$this->observe($offer,10,now()->subDays(9),'USD');$this->observe($offer,0,now()->subDays(8),'CLP');$this->observe($offer,40000,now()->subDays(7),'CLP');$this->observe($offer,35000,now()->subDays(2),'CLP');
        $response=$this->get(route('discounts'))->assertOk()->assertSee('Descuentos')->assertSee('25,0%')->assertSee('$40.000')->assertSee('$30.000')->assertSee('price-chart');
        $product=$response->viewData('products')->first();$this->assertEquals(40000,$product->initial_price);$this->assertEquals(25,$product->drop_percent);$this->assertSame(1,$response->viewData('products')->total());
        $this->get(route('album',[$offer->album_id,'cd']))->assertOk()->assertSee('25,0% desde el primer precio registrado')->assertSee(route('discounts.history',$offer));
        $this->get('/')->assertOk()->assertSee('25,0% en one')->assertSee(route('discounts'));
    }
    public function test_no_drop_is_invented_without_history_or_when_price_is_back_at_initial(): void {
        $offer=$this->offer();$this->assertSame(0,$this->get(route('discounts'))->viewData('products')->total());
        $this->observe($offer,30000,now()->subDay(),'CLP');$this->observe($offer,20000,now()->subHours(2),'CLP');
        $this->assertSame(0,$this->get(route('discounts'))->viewData('products')->total());
        $offer->update(['price'=>25000]);$this->assertSame(1,$this->get(route('discounts'))->viewData('products')->total());
        $offer->update(['availability'=>'out_of_stock']);$this->assertSame(0,$this->get(route('discounts'))->viewData('products')->total());
    }
    public function test_filters_and_pagination_preserve_store_format_condition_and_search(): void {
        $wanted=$this->offer();$this->observe($wanted,40000,now()->subDay(),'CLP');
        for($i=0;$i<13;$i++){$offer=$this->offer();$this->observe($offer,40000,now()->subDay(),'CLP');}
        $other=$this->offer('other');$other->update(['format'=>'vinyl','condition'=>'used']);$this->observe($other,50000,now()->subDay(),'CLP');
        $response=$this->get(route('discounts',['store_id'=>$wanted->store_id,'format'=>'cd','condition'=>'new','q'=>'Artist']))->assertOk();$page=$response->viewData('products');$this->assertSame(14,$page->total());$this->assertCount(12,$page->items());$this->assertStringContainsString('format=cd',$page->url(2));$this->assertStringContainsString('store_id='.$wanted->store_id,$page->url(2));
        $this->assertSame(0,$this->get(route('discounts',['store_id'=>$wanted->store_id,'format'=>'vinyl']))->viewData('products')->total());
    }
    public function test_discount_sorting_uses_percentage_in_both_directions_with_filters_and_stable_ties(): void {
        $a=$this->offer('one',70000);$this->observe($a,100000,now()->subDay(),'CLP');
        $b=$this->offer('one',10000);$this->observe($b,20000,now()->subDay(),'CLP');
        $c=$this->offer('one',30000);$this->observe($c,40000,now()->subDay(),'CLP');
        $d=$this->offer('one',14000);$this->observe($d,20000,now()->subDay(),'CLP');
        $other=$this->offer('other',5000);$this->observe($other,20000,now()->subDay(),'CLP');
        $filters=['store_id'=>$a->store_id,'format'=>'cd','condition'=>'new','q'=>'Artist'];
        $descending=$this->get(route('discounts',$filters))->assertOk()->assertSee('Mayor descuento')->assertSee('Menor descuento')->viewData('products');
        $this->assertSame([$b->id,$a->id,$d->id,$c->id],$descending->pluck('id')->all());
        $ascending=$this->get(route('discounts',$filters+['sort'=>'percent_asc']))->assertOk()->viewData('products');
        $this->assertSame([$c->id,$a->id,$d->id,$b->id],$ascending->pluck('id')->all());
        $this->assertStringContainsString('sort=percent_asc',$ascending->url(2));
        $this->assertStringContainsString('store_id='.$a->store_id,$ascending->url(2));
    }
    public function test_first_observation_uses_time_then_id_and_ignores_future_observations(): void {
        $offer=$this->offer();$this->observe($offer,35000,now()->subDay(),'CLP');$this->observe($offer,40000,now()->subDays(3),'CLP');$this->observe($offer,99900,now()->addDay(),'CLP');
        $data=$this->get(route('discounts'))->assertOk()->viewData('products')->first();$this->assertEquals(40000,$data->initial_price);
        $response=$this->get(route('discounts.history',$offer))->assertOk()->assertDontSee('$99.900');$this->assertSame(2,$response->viewData('observations')->total());
    }
    public function test_charts_preserve_first_last_and_extremes_and_full_history_is_paginated(): void {
        $offer=$this->offer();for($i=0;$i<450;$i++)$this->observe($offer,$i===200?500:40000+$i,now()->subDays(2)->addMinutes($i),'CLP');
        $chart=app(InitialPriceDiscounts::class)->charts([$offer])[$offer->id];$this->assertEquals(500,$chart['low']);$this->assertEquals(40449,$chart['high']);$this->assertEquals(40000,$chart['points'][0]['price']);$this->assertEquals(30000,$chart['points'][array_key_last($chart['points'])]['price']);$this->assertLessThanOrEqual(200,count($chart['points']));
        $response=$this->get(route('discounts.history',$offer))->assertOk();$this->assertSame(450,$response->viewData('observations')->total());$this->assertCount(50,$response->viewData('observations')->items());
    }
    public function test_invalid_excluded_inactive_stale_and_foreign_currency_offers_stay_out(): void {
        $offer=$this->offer();$this->observe($offer,40000,now()->subDays(4),'CLP');
        foreach([['excluded_reason'=>'Accessory'],['currency'=>'USD'],['checked_at'=>now()->subDays(4)],['missing_since'=>now()]] as $change){$fresh=$offer->replicate();$fresh->external_id=uniqid();$fresh->fill($change);$fresh->save();$this->observe($fresh,50000,now()->subDays(5),'CLP');}
        $response=$this->get(route('discounts'))->assertOk();$this->assertSame(1,$response->viewData('products')->total());$offer->store->update(['active'=>false]);$this->assertSame(0,$this->get(route('discounts'))->viewData('products')->total());
    }
}
