<?php

namespace Tests\Feature;

use App\Models\{Album,Offer,Store};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPoliciesTest extends TestCase {
    use RefreshDatabase;

    public function test_missing_policy_configuration_does_not_break_the_public_site(): void {
        config(['policies.pages'=>null]);
        $this->get('/')->assertOk()->assertSee('SoloDiscos no vende los discos ni recibe el pago de tus compras.');
        $this->get('/login')->assertOk();
        $this->get('/politicas/compras')->assertNotFound();
    }

    public function test_policies_are_public_and_linked_from_the_footer(): void {
        foreach(config('policies.pages') as $slug=>$policy){
            $response=$this->get(route('policies.show',$slug))->assertOk()->assertSee($policy['title'])->assertSee('SoloDiscos.cl');
            foreach(array_keys(config('policies.pages')) as $linked)$response->assertSee(route('policies.show',$linked));
            $response->assertSee('aria-current="page"',false)->assertDontSee('href="mailto:',false);
        }
        $this->get('/politicas/inexistente')->assertNotFound();
        $this->get('/politicas/../.env')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('policies.show','compras'))->assertSee('SoloDiscos no vende los discos ni recibe el pago de tus compras.');
    }

    public function test_purchase_notice_is_visible_on_album_and_price_comparisons(): void {
        $album=Album::create(['match_key'=>hash('sha256','policy-album'),'artist'=>'Artist','title'=>'Album']);
        foreach([10000,12000] as $i=>$price){
            $store=Store::create(['name'=>'Store '.$i,'url'=>'https://shop'.$i.'.example','platform'=>'shopify','currency'=>'CLP','active'=>true]);
            $offer=Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>'1','title'=>'Artist - Album','format'=>'cd','condition'=>'new','currency'=>'CLP','price'=>$price,'availability'=>'in_stock','checked_at'=>now(),'url'=>$store->url.'/disc']);
        }
        foreach([route('album',[$album,'cd']),route('rankings.sample',[$store,'new'])] as $url){
            $this->get($url)->assertOk()->assertSee('Comparas aquí. Compras en cada tienda.')->assertSee('SoloDiscos no vende los discos ni recibe el pago de tus compras.')->assertSee(route('policies.show','compras'));
        }
    }
}
