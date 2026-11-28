<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB};
use App\Models\{Album,Offer,Store};

class SeoTest extends TestCase {
    use RefreshDatabase;
    private function offer(string $format='cd'): Offer {
        $store=Store::firstOrCreate(['url'=>'https://shop.example'],['name'=>'Shop','platform'=>'shopify','currency'=>'CLP','active'=>true]);
        $album=Album::create(['artist'=>'Adele','title'=>'30 & Live </script><script>alert(1)</script>','match_key'=>uniqid()]);
        return Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>uniqid(),'title'=>'Disc','format'=>$format,'condition'=>'new','currency'=>'CLP','price'=>12000,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://shop.example/disc','image'=>'https://shop.example/cover.jpg']);
    }
    private function xpath($response): \DOMXPath {$dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());return new \DOMXPath($dom);}
    public function test_unique_metadata_canonicals_and_safe_structured_data_for_public_album(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$offer=$this->offer();
        $response=$this->get('/album/'.$offer->album_id.'/cd?return_to=%2F%3Fpage%3D2&utm_source=test')->assertOk();$xp=$this->xpath($response);
        $this->assertSame('https://www.solodiscos.cl/album/'.$offer->album_id.'/cd',$xp->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame(1,$xp->query('//title')->length);$this->assertSame(1,$xp->query('//meta[@name="description"]')->length);
        $this->assertStringContainsString('CD',$xp->evaluate('string(//title)'));$this->assertStringContainsString('$12.000',$xp->evaluate('string(//meta[@name="description"]/@content)'));
        $this->assertSame('https://shop.example/cover.jpg',$xp->evaluate('string(//meta[@property="og:image"]/@content)'));
        $graph=json_decode($xp->evaluate('string(//script[@type="application/ld+json"])'),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('MusicAlbum',$graph['@graph'][0]['@type']);$this->assertSame($offer->album->title,$graph['@graph'][0]['name']);
        $this->assertStringNotContainsString('aggregateRating',json_encode($graph));$this->assertStringNotContainsString('AggregateOffer',json_encode($graph));
        $this->assertSame(0,$xp->query('//script[not(@src) and not(@type)]')->length);
        $offer->album->update(['title'=>str_repeat('Álbum de nombre largo ',10)]);
        $long=$this->xpath($this->get('/album/'.$offer->album_id.'/cd')->assertOk());
        $this->assertLessThanOrEqual(73,mb_strlen($long->evaluate('string(//title)')));
        $this->assertStringContainsString('$12.000',$long->evaluate('string(//meta[@name="description"]/@content)'));
    }
    public function test_filters_are_noindex_but_main_formats_and_paginated_catalog_have_own_canonical(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);$offer=$this->offer();
        for($i=0;$i<25;$i++){$a=Album::create(['artist'=>'Artist','title'=>'Title '.$i,'match_key'=>'seo-'.$i]);$o=$offer->replicate();$o->album_id=$a->id;$o->external_id=uniqid();$o->save();}
        $root=$this->xpath($this->get('/')->assertOk());$this->assertSame('https://www.solodiscos.cl/',$root->evaluate('string(//link[@rel="canonical"]/@href)'));
        $page=$this->xpath($this->get('/?page=2')->assertOk());$this->assertSame('https://www.solodiscos.cl/?page=2',$page->evaluate('string(//link[@rel="canonical"]/@href)'));
        $format=$this->xpath($this->get('/?format=cd')->assertOk());$this->assertSame('https://www.solodiscos.cl/?format=cd',$format->evaluate('string(//link[@rel="canonical"]/@href)'));
        foreach(['/?q=Adele','/?condition=used','/?sort=recent','/?per_page=100','/album/'.$offer->album_id.'/cd?condition=new'] as $url){$xp=$this->xpath($this->get($url)->assertOk());$this->assertSame('noindex, follow',$xp->evaluate('string(//meta[@name="robots"]/@content)'));$this->assertSame(0,$xp->query('//link[@rel="canonical"]')->length);}
        $private=$this->get('/login')->assertOk()->assertHeader('X-Robots-Tag','noindex, nofollow');$private->assertSee('noindex, nofollow');
        $this->get('/admin/estadisticas')->assertRedirect()->assertHeader('X-Robots-Tag','noindex, nofollow');
    }
    public function test_sitemaps_are_valid_xml_and_exclude_unavailable_inactive_and_duplicate_offers(): void {
        config(['app.url'=>'https://www.solodiscos.cl']);Cache::flush();$offer=$this->offer();$clone=$offer->replicate();$clone->external_id=uniqid();$clone->save();
        $other=$this->offer('vinyl');$other->update(['availability'=>'out_of_stock']);
        $paused=$this->offer();$store=$offer->store->replicate();$store->url='https://paused.example';$store->active=false;$store->save();$paused->update(['store_id'=>$store->id]);
        $index=$this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type','application/xml; charset=UTF-8');$xml=simplexml_load_string($index->getContent());$this->assertSame('sitemapindex',$xml->getName());$this->assertCount(4,$xml->sitemap);
        $urls=$this->get('/sitemap-discos-1.xml')->assertOk();$xml=simplexml_load_string($urls->getContent());$this->assertCount(1,$xml->url);$this->assertSame('https://www.solodiscos.cl/album/'.$offer->album_id.'/cd',(string)$xml->url[0]->loc);
        $pages=$this->get('/sitemap-paginas.xml')->assertOk();$this->assertNotFalse(simplexml_load_string($pages->getContent()));$pages->assertDontSee('/admin')->assertDontSee('https://www.solodiscos.cl/tiendas/');
        $this->get('/sitemap-discos-2.xml')->assertNotFound();$this->get('/sitemap-discos-0.xml')->assertNotFound();
    }
    public function test_sitemap_shards_are_bounded_and_complete_across_1000_items(): void {
        $offer=$this->offer();$albums=[];$offers=[];
        for($i=100;$i<=1100;$i++){$albums[]=['id'=>$i,'artist'=>'Artist','title'=>'Album '.$i,'match_key'=>'shard-'.$i];$offers[]=['store_id'=>$offer->store_id,'album_id'=>$i,'external_id'=>'shard-'.$i,'title'=>'Album '.$i,'format'=>'vinyl','condition'=>'new','currency'=>'CLP','price'=>10000,'availability'=>'in_stock','checked_at'=>now(),'url'=>'https://shop.example/'.$i];}
        foreach(array_chunk($albums,300) as $chunk)DB::table('albums')->insert($chunk);
        foreach(array_chunk($offers,300) as $chunk)DB::table('offers')->insert($chunk);
        Cache::flush();$index=simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());$this->assertCount(5,$index->sitemap);
        $one=simplexml_load_string($this->get('/sitemap-discos-1.xml')->assertOk()->getContent());$two=simplexml_load_string($this->get('/sitemap-discos-2.xml')->assertOk()->getContent());
        $this->assertCount(1000,$one->url);$this->assertCount(2,$two->url);
        $urls=[];foreach([$one,$two] as $xml)foreach($xml->url as $row)$urls[]=(string)$row->loc;
        $this->assertCount(1002,array_unique($urls));
    }
    public function test_public_pages_permanently_redirect_to_configured_https_and_host(): void {
        $this->app->instance('env','production');config(['app.url'=>'https://www.solodiscos.cl']);
        $this->get('http://solodiscos.cl/?format=cd')->assertStatus(301)->assertRedirect('https://www.solodiscos.cl/?format=cd');
        $this->get('https://solodiscos.cl/descuentos?page=2')->assertStatus(301)->assertRedirect('https://www.solodiscos.cl/descuentos?page=2');
        $this->get('https://www.solodiscos.cl/')->assertOk();
    }
}
