<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,Album};
use App\Services\{PuntoMusicalReview,ProductNormalizer};

class PuntoMusicalReviewTest extends TestCase {
    use RefreshDatabase;
    private function store(string $url): Store { return Store::firstOrCreate(['url'=>$url],['name'=>'Store','platform'=>'woocommerce','currency'=>'CLP','logo_checked_at'=>now()]); }
    private function offer(Store $store,array $values): Offer {
        return Offer::create($values+['store_id'=>$store->id,'external_id'=>uniqid(),'title'=>'LP – LOVE LINES – CD','format'=>'unknown','currency'=>'CLP','price'=>29900,'availability'=>'in_stock','checked_at'=>now()->subDay(),'url'=>$store->url.'/product','source_metadata'=>['categories'=>['Anglo']]]);
    }
    public function test_reclassifies_formats_excludes_videos_and_preserves_stock_and_manual_reviews(): void {
        $store=$this->store('https://puntomusical.cl');
        $cd=$this->offer($store,[]);$checked=$cd->checked_at;
        $video=$this->offer($store,['title'=>'ARTIST – ALBUM – DVD','source_metadata'=>['categories'=>['DVD Musicales']]]);
        $manual=$this->offer($store,['reviewed'=>true]);
        $out=$this->offer($store,['title'=>'BAND – BOX (10CD)','availability'=>'out_of_stock']);
        $this->artisan('catalog:review-punto')->assertSuccessful();$this->assertSame('unknown',$cd->fresh()->format);
        $this->artisan('catalog:review-punto --apply')->assertSuccessful();
        $cd->refresh();$this->assertSame('cd',$cd->format);$this->assertSame('LP',$cd->album->artist);$this->assertTrue($checked->eq($cd->checked_at));$this->assertEquals(29900,$cd->price);
        $this->assertNotNull($video->fresh()->excluded_reason);$this->assertFalse($video->fresh()->isPublished());
        $this->assertSame('unknown',$manual->fresh()->format);$this->assertSame('out_of_stock',$out->fresh()->availability);$this->assertFalse($out->fresh()->isPublished());
    }
    public function test_local_gtin_confirms_format_but_wrong_titles_and_ambiguous_codes_stay_pending(): void {
        $other=$this->store('https://other.example');$n=app(ProductNormalizer::class);
        $album=Album::create(['artist'=>'Artist','title'=>'Album','match_key'=>$n->key('Artist','Album')]);
        $this->offer($other,['album_id'=>$album->id,'title'=>'Artist - Album','format'=>'cd','source_metadata'=>['identifiers'=>[['value'=>'696998021211']]]]);
        $source=['identifiers'=>[['value'=>'0696998021211']]];
        $r=app(PuntoMusicalReview::class);
        $result=$r->enrich('Artist – Album',$source,'');$this->assertSame('cd',$result['reference_format']);
        $this->assertArrayNotHasKey('reference_format',$r->enrich('Other – Different Album',$source,''));
        $this->offer($other,['album_id'=>$album->id,'title'=>'Artist - Album','format'=>'vinyl','source_metadata'=>['identifiers'=>[['value'=>'696998021211']]]]);
        $this->assertArrayNotHasKey('reference_format',app(PuntoMusicalReview::class)->enrich('Artist – Album',$source,''));
    }
    public function test_future_import_and_review_use_same_suffix_policy_without_excluding_cd_bonus_media(): void {
        $r=app(PuntoMusicalReview::class);$n=app(ProductNormalizer::class);
        $source=$r->enrich('BAND – ALBUM (LP+CD) – VINILO',['categories'=>['Vinilos']],'');
        $this->assertSame('vinyl',$n->parse('BAND – ALBUM (LP+CD) – VINILO','','unknown',$source)['format']);
        $this->assertSame('ALBUM',$n->albumTitle('ALBUM (10CD)'));
        $source=$r->enrich('BAND – ALBUM (2CD+DVD)',[],'');
        $this->assertSame('cd',$n->parse('BAND – ALBUM (2CD+DVD)','','unknown',$source)['format']);
    }
    public function test_soundtrack_abbreviations_are_not_album_names_and_compound_composers_are_not_split(): void {
        $other=$this->store('https://other.example');$n=app(ProductNormalizer::class);
        foreach (['Jesus Christ Superstar','Debussy'] as $artist) {
            $album=Album::create(['artist'=>$artist,'title'=>'Other','match_key'=>$n->key($artist,'Other')]);
            $this->offer($other,['album_id'=>$album->id,'title'=>$artist.' - Other','format'=>'cd']);
        }
        $review=app(PuntoMusicalReview::class);
        $this->assertArrayNotHasKey('artist',$review->enrich('JESUS CHRIST SUPERSTAR I.S.T. (2CD)',[],''));
        $this->assertArrayNotHasKey('artist',$review->enrich('DEBUSSY-RAVEL, WORKS FOR PIANO (2CD)',[],''));
    }
}
