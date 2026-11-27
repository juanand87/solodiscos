<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,ScrapeRun};
use App\Services\{StoreClient,CatalogImporter};

class LegacyWooCommerceTest extends TestCase {
    use RefreshDatabase;
    public function test_public_legacy_endpoint_uses_common_stock_price_and_grouping_rules(): void {
        $store=Store::create(['name'=>'Punto Musical','url'=>'https://puntomusical.cl','platform'=>'woocommerce','currency'=>'CLP','logo_checked_at'=>now()]);
        $product=['id'=>10,'name'=>'UPA &#8211; CIUDAD ANTERIOR &#8211; VINILO','type'=>'simple','prices'=>['price'=>'29900','currency_minor_unit'=>0,'currency_code'=>'CLP'],
            'categories'=>[['name'=>'Vinilos']],'is_in_stock'=>true,'is_purchasable'=>true,'permalink'=>$store->url.'/producto/disco','description'=>'<p>Listado de canciones</p>','sku'=>'12345'];
        $client=\Mockery::mock(StoreClient::class);
        $client->shouldReceive('get')->with($store->url,'/wp-json/wc/store/products',['per_page'=>100,'page'=>1,'stock_status'=>['instock','outofstock','onbackorder']])->twice()->andReturn([$product], [array_replace($product,['is_in_stock'=>false])]);
        $this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);
        $offer=Offer::firstOrFail();$this->assertSame('UPA',$offer->album->artist);$this->assertSame('CIUDAD ANTERIOR',$offer->album->title);
        $this->assertEquals(29900,$offer->price);$this->assertTrue($offer->isPublished());
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $this->assertDatabaseCount('offers',1);$this->assertFalse($offer->fresh()->isPublished());
    }
}
