<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Album,Offer};
use App\Services\StoreRankings;

class RecommendationsTest extends TestCase {
    use RefreshDatabase;
    private function stores(int $count): array {
        $stores=[];for($i=0;$i<$count;$i++)$stores[]=Store::create(['name'=>'Store '.$i,'url'=>'https://store'.$i.'.example','platform'=>'woocommerce','currency'=>'CLP','active'=>true,'logo_url'=>'https://store'.$i.'.example/logo.png']);return $stores;
    }
    private function product(array $stores,int $number,string $condition='new'): void {
        $album=Album::create(['match_key'=>hash('sha256','recommend-'.$number),'artist'=>'Artist','title'=>'Album '.$number]);
        foreach($stores as $i=>$store)Offer::create(['store_id'=>$store->id,'external_id'=>(string)$number,'album_id'=>$album->id,'title'=>'Artist - Album '.$number,'format'=>'cd','condition'=>$condition,'currency'=>'CLP','price'=>100+$i*10,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://example.com/product']);
    }
    public function test_threshold_is_one_hundred_and_general_combines_separate_condition_comparisons(): void {
        $stores=$this->stores(2);for($i=1;$i<=99;$i++)$this->product($stores,$i,$i<=50?'new':'used');
        $service=app(StoreRankings::class);$r=$service->calculate(['currency'=>'CLP']);
        $this->assertFalse($r['recommendations'][0]['eligible']);$this->assertSame(99,$r['recommendations'][0]['sample']);
        $this->product($stores,100,'used');$r=$service->calculate(['currency'=>'CLP']);$row=$r['recommendations'][0];
        $this->assertTrue($row['eligible']);$this->assertSame(100,$row['sample']);$this->assertEqualsWithDelta(100/110*100,$row['index'],.00001);
        $this->assertFalse($r['cheap']['new'][0]['eligible']);$this->assertFalse($r['cheap']['used'][0]['eligible']);
        $sample=$service->comparisonSample($stores[0]->id,['currency'=>'CLP','condition'=>''],1,10000);$this->assertSame($row['sample'],$sample->total());
        $new=$service->calculate(['currency'=>'CLP','condition'=>'new']);$this->assertSame(50,$new['recommendations'][0]['sample']);$this->assertFalse($new['recommendations'][0]['eligible']);
    }
    public function test_top_ten_remaining_no_stars_and_statistics_are_separate(): void {
        $stores=$this->stores(11);for($i=1;$i<=100;$i++)$this->product($stores,$i);
        $response=$this->get(route('rankings'))->assertOk()->assertSee('Productos recomendados')->assertSee('mínimo de 100')->assertSee('no opiniones de clientes')->assertDontSee('Tiendas con más productos');
        $dom=new \DOMDocument;@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());$x=new \DOMXPath($dom);
        $this->assertSame(10,$x->query('//table[@data-ranking="recommended"]/tbody/tr[@data-store-id]')->length);
        $this->assertSame(1,$x->query('//div[@data-logo-track]/a[@data-store-id]/img')->length);
        $this->assertSame('Store 10',$x->query('//div[@data-logo-track]//span[@class="store-logo-name"]')->item(0)->textContent);
        $this->assertSame(10,$x->query('//button[@class="price-rating"]')->length);
        $rows=app(StoreRankings::class)->calculate(['currency'=>'CLP'])['recommendations'];
        $this->assertSame([5.0,4.8,4.6,4.3,4.1,3.9,3.7,3.4,3.2,3.0],array_column(array_slice($rows,0,10),'stars'));
        $this->assertNull($rows[10]['stars']);
        $this->get(route('rankings.statistics'))->assertOk()->assertSee('Tiendas con más productos')->assertSee('Tiendas con mayores descuentos')->assertDontSee('data-ranking="recommended"',false);
    }
}
