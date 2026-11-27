<?php
namespace Tests\Feature;

use App\Models\{Album,Offer,Store};
use App\Services\{BillboardIdentityResolver,ProductNormalizer};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillboardIdentityTest extends TestCase
{
    use RefreshDatabase;
    public function test_a_well_supported_full_artist_name_can_disambiguate_a_short_prefix(): void
    {
        $this->reference('Pink','Other');
        foreach (['One','Two','Three'] as $album) $this->reference('Pink Floyd',$album);
        $this->reference('Pink Floyd','One','https://second.example');
        $this->assertSame('Pink Floyd',app(BillboardIdentityResolver::class)->resolve('VINILO PINK FLOYD FINAL CUT')['artist']);
    }
    public function test_known_album_can_ignore_explicit_vinyl_notes_but_keeps_album_subtitles(): void
    {
        $this->reference('Yes','Fragile');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertSame('Fragile',$resolver->resolve('VINILO YES FRAGILE (Steven Wilson Remix Colored Vinyl, Green)')['album']);
        $this->assertNull($resolver->resolve('VINILO YES FRAGILE (Live in New York)'));
    }
    private function reference(string $artist,string $title,string $url='https://other.example'): Album
    {
        $store=Store::firstOrCreate(['url'=>$url],['name'=>'Source','platform'=>'bsale']);
        $n=app(ProductNormalizer::class);
        $album=Album::firstOrCreate(['match_key'=>$n->key($artist,$title)],['artist'=>$artist,'title'=>$title]);
        Offer::create(['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>uniqid(),'title'=>$artist.' - '.$title,
            'url'=>$url.'/product','format'=>'vinyl','currency'=>'CLP','availability'=>'in_stock','price'=>29990,'checked_at'=>now()]);
        return $album;
    }
    public function test_duplicate_article_spellings_and_compound_surnames_have_one_identity(): void
    {
        $this->reference('The Beatles','Abbey Road');$this->reference('BEATLES, THE','Abbey Road','https://second.example');
        $this->reference('BEATLES','Abbey Road','https://third.example');
        $this->reference('Lana Del Rey','Born To Die');
        $this->reference('Los Bunkers','Noviembre');$this->reference('BUNKERS, LOS','Noviembre','https://second.example');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertNotNull($resolver->resolve('CD BEATLES ABBEY ROAD 2CD'));
        $this->assertSame('Lana Del Rey',$resolver->resolve('CD DEL REY,LANA BORN TO DIE')['artist']);
        $this->assertNotNull($resolver->resolve('VINILO BUNKERS NOVIEMBRE LP'));
    }
    public function test_single_word_artist_needs_multiple_references_and_does_not_swallow_initials(): void
    {
        $this->reference('Coldplay','Parachutes');$this->reference('Coldplay','X&Y','https://second.example');
        $this->reference('Love','Forever Changes');$this->reference('Love','Da Capo','https://second.example');
        foreach(['One','Two','Three'] as $album) $this->reference('CHOPIN',$album);
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertSame('Coldplay',$resolver->resolve('VINILO COLDPLAY NEW ALBUM')['artist']);
        $this->assertNull($resolver->resolve('VINILO LOVE SOMEBODY ELSE'));
        $this->assertNull($resolver->resolve('VINILO CHOPIN, F. INTIMATE BEST OF'));
    }
    public function test_glued_separators_compilations_and_album_abbreviations_are_explicit(): void
    {
        $this->reference('Slipknot','Vol 3: The Subliminal Verses');
        $this->reference('Bjork','Greatest Hits');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertSame('Slipknot',$resolver->resolve('VINILO SLIPKNOT-Vol 3: The Subliminal Verses 2LP')['artist']);
        $this->assertSame('Greatest Hits',$resolver->resolve('VINILO BJORK G.H. 2LP')['album']);
        $this->assertSame('HANK MOBLEY',$resolver->resolve('VINILO MOBLEY,HANK-Roll Call')['artist']);
        $this->assertSame('Varios Artistas',$resolver->resolve('CD VARIOUS ARTISTS LOST IN TRANSLATION')['artist']);
        $this->assertNull($resolver->resolve('CD ZABRISKIE POINT OST'));
    }
    public function test_bad_reference_headings_and_band_commas_are_not_treated_as_surname_inversions(): void
    {
        $this->reference('BEETHOVEN: THE COMPLETE WORKS','Heroic Beethoven');
        $this->reference('Black Country, New Road','For The First Time');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertNull($resolver->resolve('VINILO BEETHOVEN: THE COMPLETE WORKS HEROIC BEETHOVEN'));
        $this->assertSame('Black Country, New Road',$resolver->resolve('VINILO BLACK COUNTRY, NEW ROAD FOR THE FIRST TIME')['artist']);
    }
    public function test_known_album_and_comma_inverted_artist_match_without_fuzzy_search(): void
    {
        $this->reference('Pearl Jam','Ten');$this->reference('Alanis Morissette','Jagged Little Pill');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertSame('Ten',$resolver->resolve('VINILO PEARL JAM TEN')['album']);
        $this->assertSame('Alanis Morissette',$resolver->resolve('VINILO Morissette,Alanis Jagged Little Pill')['artist']);
        $this->assertSame('Alanis Morissette',$resolver->resolve('CD MORISSETTE,ALANIS OTHER ALBUM')['artist']);
        $this->assertNull($resolver->resolve('VINILO PEARL JAMS TEN'));
    }
    public function test_ambiguous_boundaries_and_short_artists_stay_pending(): void
    {
        $this->reference('John','Other');$this->reference('John Smith','Other');$this->reference('Love','Forever Changes');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertNull($resolver->resolve('CD JOHN SMITH NEW ALBUM'));
        $this->assertNull($resolver->resolve('CD LOVE SOMEBODY ELSE'));
        $this->assertSame('Forever Changes',$resolver->resolve('CD LOVE FOREVER CHANGES')['album']);
        $this->assertNull($resolver->resolve('CD New Artist New Album'));
    }
    public function test_explicit_delimiters_strip_format_prefix_and_artist_inference_does_not_feed_itself(): void
    {
        $this->reference('Invented Band','Album','https://www.billboard.cl');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertNull($resolver->resolve('VINILO INVENTED BAND SOMETHING'));
        $parsed=$resolver->resolve('VINILO VIVALDI, A.- Four Seasons');
        $this->assertSame('VIVALDI, A.',$parsed['artist']);$this->assertSame('Four Seasons',$parsed['album']);
        $this->assertSame('AC/DC',$resolver->resolve('VINILO AC/DC / POWER UP 1LP')['artist']);
    }
    public function test_article_aliases_and_collaborations_require_unambiguous_boundaries(): void
    {
        $this->reference('The Beatles','White Album');$this->reference('George Michael','Faith');
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertSame('The Beatles',$resolver->resolve('VINILO BEATLES WHITE ALBUM 2LP')['artist']);
        $this->assertNull($resolver->resolve('VINILO GEORGE MICHAEL AND WHAM LAST CHRISTMAS'));
        $this->assertNull($resolver->resolve('VINILO GEORGE MICHAEL & WHAM LAST CHRISTMAS'));
    }
    public function test_future_bsale_imports_use_same_rules_and_keep_identity_evidence(): void
    {
        $album=$this->reference('Pearl Jam','Ten');
        $store=Store::create(['name'=>'Billboard','url'=>'https://www.billboard.cl','platform'=>'bsale','logo_checked_at'=>now()]);
        $item=['productId'=>10,'variantId'=>20,'title'=>'VINILO PEARL JAM TEN','link'=>'/product/ten','finalPrice'=>32900,'stockControl'=>1,'totalStock'=>1];
        $html='<script>window.INIT.collections.push('.json_encode(['items'=>[$item]]).');</script>';
        $client=\Mockery::mock(\App\Services\StoreClient::class);
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/vinilos')->andReturn($html);
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/cd')->andReturn('<script>window.INIT.collections.push({"items":[]});</script>');
        $this->app->instance(\App\Services\StoreClient::class,$client);
        $run=\App\Models\ScrapeRun::create(['store_id'=>$store->id]);
        app(\App\Services\CatalogImporter::class)->run($run);
        $offer=Offer::where('store_id',$store->id)->firstOrFail();
        $this->assertSame('success',$run->fresh()->status);
        $this->assertSame($album->id,$offer->album_id);$this->assertTrue($offer->isPublished());
        $this->assertNotEmpty($offer->source_metadata['identity_evidence']);
    }
    public function test_apply_links_only_pending_unreviewed_offers_without_changing_stock_or_prices(): void
    {
        $album=$this->reference('Pearl Jam','Ten');
        $store=Store::create(['name'=>'Billboard','url'=>'https://www.billboard.cl','platform'=>'bsale']);
        $values=['store_id'=>$store->id,'title'=>'VINILO PEARL JAM TEN','url'=>$store->url.'/product/ten','format'=>'vinyl','currency'=>'CLP','availability'=>'out_of_stock','price'=>32900,'checked_at'=>now()->subDays(2)];
        $pending=Offer::create($values+['external_id'=>'1']);
        $manual=Offer::create($values+['external_id'=>'2','reviewed'=>true]);
        $checked=$pending->checked_at;
        $this->artisan('catalog:identify-billboard')->assertSuccessful();
        $this->assertNull($pending->fresh()->album_id);
        $this->artisan('catalog:identify-billboard --apply')->assertSuccessful();
        $pending->refresh();
        $this->assertSame($album->id,$pending->album_id);$this->assertSame('out_of_stock',$pending->availability);
        $this->assertEquals(32900,$pending->price);$this->assertTrue($checked->eq($pending->checked_at));
        $this->assertFalse($pending->isPublished());$this->assertNull($manual->fresh()->album_id);
    }
    public function test_verified_barcode_identity_survives_reimport_but_not_a_changed_title(): void
    {
        $store=Store::create(['name'=>'Billboard','url'=>'https://www.billboard.cl','platform'=>'bsale','logo_checked_at'=>now()]);
        $title='VINILO UNKNOWN GROUP UNKNOWN ALBUM';
        $verified=['artist'=>'Unknown Group','album'=>'Unknown Album','evidence'=>'GTIN verificado','title_hash'=>hash('sha256',$title)];
        $offer=Offer::create(['store_id'=>$store->id,'external_id'=>'20','title'=>$title,'url'=>$store->url.'/product/album',
            'format'=>'vinyl','currency'=>'CLP','availability'=>'in_stock','price'=>32900,'checked_at'=>now(),
            'source_metadata'=>['verified_identity'=>$verified,'barcode'=>'098787069013']]);
        $item=['productId'=>10,'variantId'=>20,'title'=>$title,'link'=>'/product/album','finalPrice'=>32900,'stockControl'=>1,'totalStock'=>0];
        $client=\Mockery::mock(\App\Services\StoreClient::class);
        $html='<script>window.INIT.collections.push('.json_encode(['items'=>[$item]]).');</script>';
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/vinilos')->andReturn($html);
        $client->shouldReceive('html')->withArgs(fn($base,$path,$query)=>$path==='/collection/cd')->andReturn('<script>window.INIT.collections.push({"items":[]});</script>');
        $this->app->instance(\App\Services\StoreClient::class,$client);
        app(\App\Services\CatalogImporter::class)->run(\App\Models\ScrapeRun::create(['store_id'=>$store->id]));
        $offer->refresh();
        $this->assertSame('Unknown Group',$offer->album->artist);
        $this->assertSame('out_of_stock',$offer->availability);$this->assertFalse($offer->isPublished());
        $this->assertSame($verified,$offer->source_metadata['verified_identity']);
        $resolver=app(BillboardIdentityResolver::class);
        $this->assertNull($resolver->resolve($title.' CHANGED',$verified));
    }
}
