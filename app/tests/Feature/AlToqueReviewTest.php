<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Offer,Store,Album};
use App\Services\{AlToqueReview,ProductNormalizer};

class AlToqueReviewTest extends TestCase {
    use RefreshDatabase;
    private function store(): Store { return Store::create(['name'=>'Al Toque Records','url'=>'https://altoquerecords.cl','platform'=>'shopify','currency'=>'CLP']); }
    private function offer(Store $store,array $extra=[]): Offer { return Offer::create($extra+['store_id'=>$store->id,'external_id'=>uniqid(),'title'=>'M.I.A. - MATANGI','format'=>'unknown','price'=>29990,'currency'=>'CLP','availability'=>'in_stock','checked_at'=>now()->subDay(),'url'=>$store->url.'/products/album','description'=>"Sello:\nLabel\nFormato:\n2 x Vinilo, LP, Album\nPaís:\nUS",'source_metadata'=>['product_type'=>'Nuevos & Reediciones']]); }
    public function test_format_fields_in_both_languages_and_explicit_dimensions_are_recognized(): void {
        $r=app(AlToqueReview::class);$n=app(ProductNormalizer::class);
        foreach(["Formato:\n2 x Vinilo, LP\nCD, Bonus\nPaís:\nUS","Format:\nVinyl, LP\nCountry:\nUS"] as $description) {
            $source=$r->enrich('BAND - ALBUM',[],$description);
            $this->assertSame('vinyl',$n->parse('BAND - ALBUM','','unknown',$source,$description)['format']);
        }
        $source=$r->enrich('BAND - ALBUM (10" SINGLE)',[],'');$this->assertSame('vinyl',$n->parse('BAND - ALBUM (10" SINGLE)','','unknown',$source)['format']);
        $source=$r->enrich('BAND - COMBO PROMO (VINILO + CD)',[],'');$this->assertSame('unknown',$n->parse('BAND - COMBO PROMO (VINILO + CD)','','unknown',$source)['format']);
    }
    public function test_n_sku_is_only_derived_with_valid_checksum_and_original_is_kept(): void {
        $r=app(AlToqueReview::class);
        $source=$r->enrich('BAND - ALBUM',['sku'=>'N696998021211'],'');
        $this->assertSame('N696998021211',$source['sku']);$this->assertSame('00696998021211',$source['identifiers'][0]['gtin']);
        $source=$r->enrich('BAND - ALBUM',['sku'=>'N696998021212'],'');$this->assertArrayNotHasKey('identifiers',$source);
    }
    public function test_soundtrack_titles_are_not_split_into_a_band_prefix(): void {
        $other=Store::create(['name'=>'Other','url'=>'https://other.example','platform'=>'shopify','currency'=>'CLP']);
        $n=app(ProductNormalizer::class);
        foreach(['One','Two','Three'] as $title) {
            $album=Album::create(['artist'=>'Pulp','title'=>$title,'match_key'=>$n->key('Pulp',$title)]);
            $this->offer($other,['album_id'=>$album->id,'title'=>'Pulp - '.$title,'format'=>'vinyl']);
        }
        $source=app(AlToqueReview::class)->enrich('PULP FICTION (MUSIC FROM THE MOTION PICTURE) (VINILO SIMPLE)',['tags'=>['SOUNDTRACK']],'');
        $this->assertArrayNotHasKey('artist',$source);
    }
    public function test_apply_preserves_stock_price_condition_manual_reviews_and_checked_date(): void {
        $store=$this->store();$offer=$this->offer($store,['condition'=>'used','availability'=>'out_of_stock']);$checked=$offer->checked_at;
        $manual=$this->offer($store,['reviewed'=>true]);$gift=$this->offer($store,['title'=>'GIFT CARD - ALTOQUE RECORDS $50000']);
        $this->artisan('catalog:review-altoque')->assertSuccessful();$this->assertSame('unknown',$offer->fresh()->format);
        $this->artisan('catalog:review-altoque --apply')->assertSuccessful();$offer->refresh();
        $this->assertSame('vinyl',$offer->format);$this->assertSame('M.I.A.',$offer->album->artist);$this->assertSame('used',$offer->condition);
        $this->assertSame('out_of_stock',$offer->availability);$this->assertEquals(29990,$offer->price);$this->assertTrue($checked->eq($offer->checked_at));
        $this->assertFalse($offer->isPublished());$this->assertSame('unknown',$manual->fresh()->format);$this->assertNotNull($gift->fresh()->excluded_reason);
    }
}
