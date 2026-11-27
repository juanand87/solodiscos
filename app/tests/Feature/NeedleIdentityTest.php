<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Album,Offer,ScrapeRun};
use App\Services\{NeedleIdentityResolver,ProductNormalizer,CatalogImporter,StoreClient};

class NeedleIdentityTest extends TestCase {
    use RefreshDatabase;
    private function reference(string $artist,string $title,string $format='cd'): Album {
        $store=Store::firstOrCreate(['url'=>'https://other.example'],['name'=>'Other','platform'=>'shopify','currency'=>'CLP']);
        $album=Album::firstOrCreate(['match_key'=>app(ProductNormalizer::class)->key($artist,$title)],['artist'=>$artist,'title'=>$title]);
        Offer::create(['store_id'=>$store->id,'external_id'=>uniqid(),'album_id'=>$album->id,'title'=>$artist.' – '.$title,'format'=>$format,'condition'=>'new','price'=>10000,'availability'=>'in_stock','checked_at'=>now(),'currency'=>'CLP','url'=>'https://other.example/product']);
        return $album;
    }
    public function test_vendor_resolves_album_only_reverse_and_multiple_delimiters_and_reuses_reference(): void {
        $album=$this->reference('Coldplay','X&Y');$resolver=app(NeedleIdentityResolver::class);
        foreach(['X&y (CD) - Coldplay','X&Y','Coldplay - X&Y'] as $title){
            $source=$resolver->enrich($title,['product_type'=>'CD','vendor'=>'Coldplay']);
            $this->assertSame('Coldplay',$source['artist']);$this->assertSame('X&Y',$source['album']);$this->assertSame($album->id,$source['needle_album_id']);
        }
        $source=$resolver->enrich('Drumming Is A Language 1990 - 2011 (CD) - African Head Charge',['product_type'=>'CD','vendor'=>'African Head Charge']);
        $this->assertSame('Drumming Is A Language 1990 - 2011',$source['album']);
        $source=$resolver->enrich('Endlos',['product_type'=>'Vinilo','vendor'=>'Como Asesinar a Felipes']);
        $this->assertSame('Como Asesinar a Felipes',$source['artist']);$this->assertSame('Endlos',$source['album']);
    }
    public function test_legacy_titles_require_complete_reference_and_unknown_or_various_artists_stay_pending(): void {
        $this->reference('Coldplay','Everyday Life');$resolver=app(NeedleIdentityResolver::class);
        $source=$resolver->enrich('Everyday Life (CD) - Coldplay',['product_type'=>'CD']);$this->assertFalse($source['identity_pending']);
        foreach([['Everyday Life','Needle'],['Greatest Hits','Varios Artistas'],['Unrelated - Someone','']] as [$title,$vendor]){
            $source=$resolver->enrich($title,['product_type'=>'CD','vendor'=>$vendor]);$this->assertTrue($source['identity_pending']);$this->assertArrayNotHasKey('artist',$source);
        }
    }
    public function test_matching_requires_same_format_and_devices_and_books_are_not_music(): void {
        $this->reference('Coldplay','X&Y','cd');$resolver=app(NeedleIdentityResolver::class);
        $source=$resolver->enrich('X&Y',['product_type'=>'Vinilo','vendor'=>'Coldplay']);$this->assertArrayNotHasKey('needle_album_id',$source);
        foreach([['Reproductor de CD','Reproductor CD'],['Pack Hi-Fi Vinilo: Tornamesa + Parlantes','Pack'],['Gift Card Needle','Gift Card'],['33 1/3 Parallel Lines – Kembrew McLeod','Libro']] as [$title,$type]){
            $source=$resolver->enrich($title,['product_type'=>$type,'vendor'=>'Needle']);
            $parsed=app(ProductNormalizer::class)->parse($title,'','unknown',$source);$this->assertNotNull($parsed['excluded_reason']);$this->assertNull($parsed['artist']);
        }
    }
    public function test_import_reuses_canonical_album_preserves_manual_album_and_other_stores_keep_normal_order(): void {
        $album=$this->reference('Coldplay','Everyday Life');$needle=Store::create(['name'=>'Needle','url'=>'https://needle.cl','platform'=>'shopify','currency'=>'CLP','default_condition'=>'new','logo_checked_at'=>now()]);
        $manual=Album::create(['match_key'=>str_repeat('b',64),'artist'=>'Manual','title'=>'Protected']);
        $offer=Offer::create(['store_id'=>$needle->id,'external_id'=>'2','album_id'=>$manual->id,'reviewed'=>true,'title'=>'Old','format'=>'cd','condition'=>'new','currency'=>'CLP','url'=>'https://needle.cl/product']);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>[
            ['title'=>'Everyday Life (CD) - Coldplay','vendor'=>'Coldplay','product_type'=>'CD','handle'=>'everyday','variants'=>[['id'=>1,'title'=>'Default Title','price'=>'10000','available'=>true],['id'=>2,'title'=>'Default Title','price'=>'12000','available'=>true]]]
        ]]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$needle->id]);app(CatalogImporter::class)->run($run);$this->assertSame('success',$run->fresh()->status);
        $auto=$needle->offers()->where('external_id','1')->firstOrFail();$this->assertSame($album->id,$auto->album_id);$this->assertSame('Coldplay',$auto->source_metadata['vendor']);
        $this->assertSame($manual->id,$offer->fresh()->album_id);$this->assertTrue($offer->fresh()->reviewed);
        $other=Store::where('url','https://other.example')->first();$other->update(['logo_checked_at'=>now()]);
        $newClient=\Mockery::mock(StoreClient::class);$newClient->shouldReceive('get')->andReturn(['products'=>[['title'=>'Artist - Album','vendor'=>'Label','product_type'=>'CD','handle'=>'album','variants'=>[['id'=>9,'price'=>'10000','available'=>true]]]]]);$this->app->instance(StoreClient::class,$newClient);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$other->id]));
        $normal=$other->offers()->where('external_id','9')->firstOrFail();$this->assertSame('Artist',$normal->album->artist);$this->assertSame('Album',$normal->album->title);
    }
}
