<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Album,Offer,User};
use App\Services\StoreRankings;

class RankingSamplesTest extends TestCase {
    use RefreshDatabase;
    private function store(string $name): Store {return Store::create(['name'=>$name,'url'=>'https://'.strtolower($name).'.example','platform'=>'woocommerce','currency'=>'CLP','active'=>true,'logo_url'=>'https://'.strtolower($name).'.example/logo.png']);}
    private function offer(Store $store,int $album,int $price,array $extra=[]): Offer {
        $a=Album::firstOrCreate(['match_key'=>hash('sha256','sample-'.$album)],['artist'=>'Artist','title'=>'Album '.$album]);
        return Offer::create($extra+['store_id'=>$store->id,'external_id'=>uniqid(),'album_id'=>$a->id,'title'=>'Artist - Album '.$album,'format'=>'cd','condition'=>'new','currency'=>'CLP','price'=>$price,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://'.$store->name.'.example/product']);
    }
    public function test_sample_matches_ranking_and_selects_three_cheapest_distinct_stores_or_only_two(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');$d=$this->store('D');
        $this->offer($a,1,400);$this->offer($b,1,100);$this->offer($b,1,900);$this->offer($c,1,200);$this->offer($d,1,300);
        $this->offer($a,2,100);$this->offer($b,2,100);$this->offer($a,3,100); // no competitor
        $this->offer($a,4,100);$this->offer($b,4,100,['availability'=>'out_of_stock']);
        $this->offer($a,5,100);$this->offer($b,5,100,['checked_at'=>now()->subHours(73)]);
        $this->offer($a,6,100);$this->offer($b,6,100,['condition'=>'used']);
        $service=app(StoreRankings::class);$filters=['currency'=>'CLP','condition'=>'new','format'=>'cd'];$samples=$service->comparisonSample($a->id,$filters);
        $rank=collect($service->calculate($filters)['cheap']['new'])->first(fn($r)=>$r['store']->id===$a->id);
        $this->assertSame($rank['sample'],$samples->total());$this->assertSame(2,$samples->total());
        $this->assertSame([$b->id,$c->id,$d->id],array_column($samples[0]['offers'],'store_id'));
        $this->assertEquals(400,$samples[0]['anchor']->price);$this->assertSame(4,$samples[0]['store_count']);
        $this->assertCount(2,$samples[1]['offers']);$this->assertSame([1,1],array_column($samples[1]['offers'],'price_rank'));
        $this->get(route('rankings.sample',['store'=>$a,'condition'=>'new','format'=>'cd']))->assertOk()->assertSee('Álbumes comparados de A')->assertSee('Mejor precio')->assertSee('Ahorra hasta $200')->assertSee('$400')->assertDontSee('Comprobado:')->assertDontSee('Sin envío');
        $this->get(route('rankings.sample',['store'=>$a,'condition'=>'new','format'=>'cd']))->assertOk()->assertSee('Ver todas las tiendas')->assertSee('Mostrando las 3 tiendas más baratas de 4.')->assertSee(route('album',[$samples[0]['anchor']->album_id,'cd']));
        $this->get(route('rankings',['format'=>'cd']))->assertOk()->assertSee(route('rankings.sample',['store'=>$a->id,'condition'=>'all','format'=>'cd','currency'=>'CLP']));
    }
    public function test_pagination_filters_validation_and_admin_access(): void {
        $a=$this->store('A');$b=$this->store('B');
        for($i=1;$i<=14;$i++){$this->offer($a,$i,100);$this->offer($b,$i,200);}
        $this->offer($a,30,100,['format'=>'vinyl','condition'=>'used','currency'=>'USD']);$this->offer($b,30,200,['format'=>'vinyl','condition'=>'used','currency'=>'USD']);
        $page=app(StoreRankings::class)->comparisonSample($a->id,['format'=>'cd','condition'=>'new','currency'=>'CLP'],2);
        $this->assertSame(14,$page->total());$this->assertCount(2,$page->items());
        $this->get(route('rankings.sample',['store'=>$a,'condition'=>'used','format'=>'vinyl','currency'=>'USD']))->assertRedirect()->assertSessionHasErrors('currency');
        $this->get(route('rankings.sample',['store'=>$a,'condition'=>'new','format'=>'dvd']))->assertRedirect()->assertSessionHasErrors('format');
        $this->get(route('rankings.sample',['store'=>$a,'condition'=>'new','page'=>0]))->assertRedirect()->assertSessionHasErrors('page');
        $this->get(route('admin.rankings.sample',['store'=>$a,'condition'=>'new']))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.rankings.sample',['store'=>$a,'condition'=>'new','page'=>2,'format'=>'cd','currency'=>'CLP']))->assertOk()->assertSee('Página 2 de 2')->assertSee('/admin/rankings?',false);
        $a->update(['active'=>false]);$this->get(route('rankings.sample',['store'=>$a,'condition'=>'new']))->assertNotFound();
    }
    public function test_minimum_price_links_include_ties_match_counts_and_preserve_filters_and_pagination(): void {
        $a=$this->store('A');$b=$this->store('B');$c=$this->store('C');
        for($i=1;$i<=14;$i++){$this->offer($a,$i,100);$this->offer($b,$i,$i===1?100:200);$this->offer($c,$i,300);}
        $this->offer($a,1,900); // Duplicate publication does not affect the minimum.
        $this->offer($a,15,200);$this->offer($b,15,100); // Excluded: a cheaper competitor exists.
        foreach([['format'=>'vinyl'],['condition'=>'used'],['currency'=>'USD']] as $extra){$this->offer($a,30,100,$extra);$this->offer($b,30,100,$extra);}
        $service=app(StoreRankings::class);
        foreach([['format'=>'cd','condition'=>'new','currency'=>'CLP'],['format'=>'vinyl','condition'=>'new','currency'=>'CLP'],['format'=>'cd','condition'=>'used','currency'=>'CLP'],['format'=>'cd','condition'=>'new','currency'=>'USD']] as $filters){
            $sample=$service->comparisonSample($a->id,$filters+['cheapest'=>true]);
            $row=collect($service->calculate($filters)['recommendations'])->firstWhere('store.id',$a->id);
            $this->assertSame($row['cheaper_products'],$sample->total());
            foreach($sample as $item)$this->assertSame(1,$item['anchor']->price_rank);
        }
        $params=['store'=>$a,'condition'=>'new','format'=>'cd','currency'=>'CLP','cheapest'=>1];
        $response=$this->get(route('rankings.sample',$params))->assertOk()->assertSee('Productos con precio mínimo de A')->assertSee('Incluye empates')->assertDontSee('Album 15<',false);
        $response->assertSee('cheapest=1',false)->assertSee('format=cd',false)->assertSee('currency=CLP',false);
        $this->get(route('rankings.sample',$params+['page'=>2]))->assertOk()->assertSee('Página 2 de 2');
        $this->actingAs(User::factory()->create())->get(route('admin.rankings.sample',$params))->assertOk()->assertSee('Productos con precio mínimo de A');
    }
    public function test_three_tied_sellers_and_all_stores_link_keep_the_same_comparison_filters(): void {
        $stores=[];foreach(['A','B','C','D','E'] as $name){$store=$this->store($name);$stores[]=$store;$offer=$this->offer($store,99,100);}
        $this->offer($stores[0],99,1,['condition'=>'used']);
        $this->offer($stores[0],99,1,['condition'=>'unknown']);
        $this->offer($stores[0],99,1,['currency'=>'USD']);
        $this->offer($stores[0],99,1,['checked_at'=>now()->subHours(80)]);
        $filters=['format'=>'cd','condition'=>'new','currency'=>'CLP','cheapest'=>true];
        $item=app(StoreRankings::class)->comparisonSample($stores[0]->id,$filters)[0];
        $this->assertSame(5,$item['store_count']);$this->assertCount(3,$item['offers']);
        $this->assertSame([1,1,1],array_column($item['offers'],'price_rank'));
        $url=route('album',[$offer->album_id,'cd','condition'=>'new','currency'=>'CLP','comparison'=>1]);
        $this->get(route('rankings.sample',['store'=>$stores[0],'condition'=>'new','format'=>'cd','cheapest'=>1]))->assertOk()->assertSee('Mostrando las 3 tiendas más baratas de 5.')->assertSee($url);
        $response=$this->get($url)->assertOk();$offers=$response->viewData('album')->offers;
        $this->assertCount(5,$offers);foreach($offers as $candidate){$this->assertSame('new',$candidate->condition);$this->assertSame('CLP',$candidate->currency);$this->assertEquals(100,$candidate->price);}
    }
}
