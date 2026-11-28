<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\{Album,Offer,Store};
use App\Services\CatalogDiscovery;

class DiscoverySeoTest extends TestCase {
    use RefreshDatabase;
    private function offer(string $artist='Adele',string $title='30',string $format='cd',?Store $store=null): Offer {
        $store??=Store::firstOrCreate(['url'=>'https://shop.example'],['name'=>'Shop','platform'=>'shopify','currency'=>'CLP','active'=>true]);
        $album=Album::create(['artist'=>$artist,'title'=>$title,'match_key'=>uniqid()]);
        return Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>uniqid(),'title'=>$artist.' — '.$title,'format'=>$format,'condition'=>'new','currency'=>'CLP','price'=>12000,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://shop.example/disc','image'=>'https://shop.example/cover.jpg']);
    }
    private function xpath($response): \DOMXPath {$dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());return new \DOMXPath($dom);}
    public function test_artist_aliases_share_one_canonical_hub_and_cards_link_to_original_album_pages(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$one=$this->offer('AC/DC','Powerage');$two=$this->offer('AC / DC','Back in Black','vinyl');$this->offer('Other','Other disc');
        $d=app(CatalogDiscovery::class);$this->assertSame($d->artistKey('AC/DC'),$d->artistKey('AC / DC'));
        $profile=$d->artists()[$d->artistKey('AC/DC')];$this->assertSame(2,$profile['count']);
        $response=$this->get($profile['path'])->assertOk();$this->assertSame(2,$response->viewData('publications')->total());
        $xp=$this->xpath($response);$this->assertSame('https://www.solodiscos.cl'.$profile['path'],$xp->evaluate('string(//link[@rel="canonical"]/@href)'));
        $response->assertSee('/album/'.$one->album_id.'/cd')->assertSee('/album/'.$two->album_id.'/vinyl');
        $this->get('/artistas/'.$profile['key'].'/incorrecto?format=cd')->assertStatus(301)->assertRedirect($profile['path'].'?format=cd');
        $this->get('/artistas/'.$profile['key'])->assertStatus(301)->assertRedirect($profile['path']);
    }
    public function test_store_catalogue_uses_only_its_offers_and_keeps_private_state_out(): void {
        $one=$this->offer();$other=Store::create(['name'=>'Other','url'=>'https://other.example','platform'=>'shopify','active'=>true,'commercial_agreement_reference'=>'PRIVATE-FOLIO']);
        $this->offer('Other Artist','Other Album','vinyl',$other);
        $d=app(CatalogDiscovery::class);$response=$this->get($d->storePath($one->store))->assertOk();
        $this->assertSame(1,$response->viewData('publications')->total());$response->assertSee('30')->assertDontSee('Other Album')->assertDontSee('PRIVATE-FOLIO');
        $this->get('/tiendas-de-discos/'.$one->store_id)->assertStatus(301)->assertRedirect($d->storePath($one->store));
        $other->update(['active'=>false]);$this->get($d->storePath($other))->assertNotFound()->assertHeader('X-Robots-Tag','noindex, nofollow');
        $this->get('/tiendas-de-discos?page=2')->assertNotFound();
    }
    public function test_format_pages_have_their_own_canonical_but_search_and_condition_are_noindex(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$offer=$this->offer();$this->offer('Adele','25','vinyl');$d=app(CatalogDiscovery::class);$path=$d->artists()[$d->artistKey('Adele')]['path'];
        $response=$this->get($path.'?format=cd')->assertOk();$this->assertSame(1,$response->viewData('publications')->total());
        $xp=$this->xpath($response);$this->assertSame('https://www.solodiscos.cl'.$path.'?format=cd',$xp->evaluate('string(//link[@rel="canonical"]/@href)'));
        foreach(['?condition=new','?q=30','?format=vinyl&condition=used'] as $query){$xp=$this->xpath($this->get($path.$query)->assertOk());$this->assertSame('noindex, follow',$xp->evaluate('string(//meta[@name="robots"]/@content)'));$this->assertSame(0,$xp->query('//link[@rel="canonical"]')->length);}
        $this->get($path.'?page=9999')->assertNotFound();$this->get('/artistas?per_page=100000&q=Adele')->assertOk();
        $this->get('/artistas/0000000000000000/missing')->assertNotFound();
    }
    public function test_hubs_pagination_graph_and_return_links_preserve_format_and_scope(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$offer=$this->offer();for($i=0;$i<26;$i++)$this->offer('Adele','Album '.$i);
        $d=app(CatalogDiscovery::class);$path=$d->artists()[$d->artistKey('Adele')]['path'];$response=$this->get($path.'?format=cd&page=2')->assertOk();
        $this->assertSame(2,$response->viewData('publications')->count());$xp=$this->xpath($response);
        $this->assertSame('https://www.solodiscos.cl'.$path.'?format=cd&page=2',$xp->evaluate('string(//link[@rel="canonical"]/@href)'));
        $graph=json_decode($xp->evaluate('string(//script[@type="application/ld+json"])'),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('CollectionPage',$graph['@graph'][0]['@type']);$this->assertSame(26,$graph['@graph'][0]['mainEntity']['itemListElement'][0]['position']);
        $this->assertStringNotContainsString('return_to',json_encode($graph));
        $album=$this->get(route('album',[$offer->album_id,'cd','return_to'=>$path.'?format=cd&page=2']))->assertOk();
        $this->assertSame($path.'?format=cd&page=2',$album->viewData('backUrl'));$album->assertSee('Más discos de Adele')->assertSee('/tiendas-de-discos/');
    }
    public function test_discovery_sitemaps_only_contain_public_available_artists_and_stores(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$offer=$this->offer();$hidden=$this->offer('Hidden','Hidden Album');$hidden->update(['availability'=>'out_of_stock']);Cache::flush();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/sitemap-artistas-1.xml')->assertSee('/sitemap-tiendas.xml');
        $artists=simplexml_load_string($this->get('/sitemap-artistas-1.xml')->assertOk()->getContent());$this->assertCount(1,$artists->url);
        $stores=simplexml_load_string($this->get('/sitemap-tiendas.xml')->assertOk()->getContent());$this->assertCount(1,$stores->url);
        $this->assertStringNotContainsString('/tiendas/ingresar',(string)$stores->url[0]->loc);
        $this->get('/sitemap-artistas-2.xml')->assertNotFound();
        $this->get('/')->assertOk()->assertSee('Explorar artistas')->assertSee('Tiendas de discos');
    }
}
