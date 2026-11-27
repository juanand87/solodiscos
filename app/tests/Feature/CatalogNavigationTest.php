<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Album,Offer,Store};

class CatalogNavigationTest extends TestCase {
    use RefreshDatabase;
    private function catalogue(int $count=1): Offer {
        $store=Store::create(['name'=>'Shop','url'=>'https://shop.example','platform'=>'shopify','currency'=>'CLP','logo_url'=>'https://shop.example/logo.png']);
        for($i=1;$i<=$count;$i++){
            $album=Album::create(['match_key'=>hash('sha256','navigation-'.$i),'artist'=>'Artist','title'=>'Album '.$i]);
            $offer=Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>(string)$i,'title'=>'Artist - Album '.$i,'format'=>'cd','condition'=>'new','currency'=>'CLP','price'=>10000,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://shop.example/product/'.$i]);
        }
        return $offer;
    }
    public function test_page_sizes_are_bounded_and_carried_through_pagination_and_album_links(): void {
        $this->catalogue(260);
        foreach([25,50,100,250] as $size){
            $response=$this->get(route('catalog',['q'=>'Artist','format'=>'cd','condition'=>'new','sort'=>'stores','per_page'=>$size]))->assertOk();
            $page=$response->viewData('publications');$this->assertCount($size,$page->items());$this->assertSame(260,$page->total());
            $this->assertStringContainsString('per_page='.$size,$page->url(2));
        }
        $this->assertCount(25,$this->get('/?q=Artist')->viewData('publications')->items());
        foreach([24,251,10000,'all'] as $size)$this->get('/?per_page='.$size)->assertRedirect()->assertSessionHasErrors('per_page');
        $return='/?q=Artist&format=cd&condition=new&sort=stores&per_page=50&page=2';
        $response=$this->get($return)->assertOk();$first=$response->viewData('publications')->first();
        $response->assertSee(route('album',[$first->album_id,'cd','return_to'=>$return]));
        $this->assertCount(50,$response->viewData('publications')->items());
    }
    public function test_album_has_two_clear_return_links_and_preserves_origin_between_formats(): void {
        $offer=$this->catalogue();$vinyl=$offer->replicate();$vinyl->external_id='vinyl';$vinyl->format='vinyl';$vinyl->save();
        $return='/?q=Artist&format=cd&condition=new&sort=stores&per_page=100&page=3';
        $response=$this->get(route('album',[$offer->album_id,'cd','return_to'=>$return]))->assertOk()->assertDontSee('Comprobado:')->assertDontSee('Sin envío')->assertSee('+ envío');
        $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());$xpath=new \DOMXPath($dom);
        $links=$xpath->query('//a[@data-catalog-return]');$this->assertSame(2,$links->length);
        foreach($links as $link)$this->assertSame($return,$link->getAttribute('href'));
        $response->assertSee(route('album',[$offer->album_id,'vinyl','return_to'=>$return]));
    }
    public function test_return_links_only_accept_the_catalog_path(): void {
        $offer=$this->catalogue();
        foreach(['https://outside.example','//outside.example','/admin',"/?q=x\r\ny"] as $return){
            $response=$this->get(route('album',[$offer->album_id,'cd','return_to'=>$return]))->assertOk();
            $this->assertSame(route('catalog').'#catalogo',$response->viewData('backUrl'));
        }
    }
    public function test_guest_navigation_and_logo_controls_are_public_without_an_admin_button(): void {
        $this->catalogue();
        $this->get('/')->assertOk()->assertSee('Top 10 Tiendas')->assertSee('Ver tiendas anteriores')->assertSee('Pausar avance de tiendas')
            ->assertSee('Resultados por página')->assertSee('250 discos')->assertDontSee('href="'.route('login').'"',false)->assertSee('data-logo-carousel',false);
        $this->get('/login')->assertOk();
    }
}
