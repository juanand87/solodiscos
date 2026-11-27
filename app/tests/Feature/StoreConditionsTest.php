<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\{Store,Offer,Album,ScrapeRun,User};
use App\Services\{StoreConditions,PriceHistory,StoreClient,CatalogImporter};

class StoreConditionsTest extends TestCase {
    use RefreshDatabase;
    private function store(): Store {return Store::create(['name'=>'Punto','url'=>'https://example.com','platform'=>'shopify','currency'=>'CLP','default_condition'=>'unknown','logo_checked_at'=>now()]);}
    private function offer(Store $store,array $extra=[]): Offer {
        $album=Album::firstOrCreate(['match_key'=>str_repeat('a',64)],['artist'=>'Artist','title'=>'Album']);
        return Offer::create($extra+['store_id'=>$store->id,'external_id'=>uniqid(),'album_id'=>$album->id,'title'=>'Artist - Album','format'=>'cd',
            'condition'=>'unknown','price'=>12000,'currency'=>'CLP','availability'=>'in_stock','checked_at'=>now(),'url'=>'https://example.com/product']);
    }
    public function test_forced_condition_applies_existing_catalog_including_reviewed_offers_without_faking_price_or_stock_changes(): void {
        $store=$this->store();$offer=$this->offer($store,['reviewed'=>true]);app(PriceHistory::class)->record($offer);
        $before=$offer->getAttributes();$count=app(StoreConditions::class)->configure($store,['default_condition'=>'new']);
        $this->assertSame(1,$count);$offer->refresh();$this->assertSame('new',$offer->condition);$this->assertTrue($offer->reviewed);
        foreach(['album_id','format','price','availability','checked_at','title'] as $field)$this->assertSame($before[$field],$offer->getAttributes()[$field]);
        $this->assertDatabaseHas('offer_price_tracking',['offer_id'=>$offer->id,'status'=>'closed','close_reason'=>'condition_policy_changed']);
        $this->assertDatabaseCount('offer_events',0);$this->assertDatabaseCount('offer_observations',0);
        $this->assertSame(0,app(StoreConditions::class)->configure($store,['default_condition'=>'new']));
        app(StoreConditions::class)->configure($store,['default_condition'=>'used']);$this->assertSame('used',$offer->fresh()->condition);
    }
    public function test_auto_mode_reclassifies_from_source_and_preserves_manual_corrections(): void {
        $store=$this->store();$store->update(['default_condition'=>'new']);
        $new=$this->offer($store,['condition'=>'new','source_metadata'=>['tags'=>['Nuevo']]]);
        $used=$this->offer($store,['condition'=>'new','source_metadata'=>['categories'=>['Usados']]]);
        $unknown=$this->offer($store,['condition'=>'new']);$manual=$this->offer($store,['condition'=>'new','reviewed'=>true]);
        $this->assertSame(2,app(StoreConditions::class)->configure($store,['default_condition'=>'unknown']));
        $this->assertSame('new',$new->fresh()->condition);$this->assertSame('used',$used->fresh()->condition);
        $this->assertSame('unknown',$unknown->fresh()->condition);$this->assertSame('new',$manual->fresh()->condition);
    }
    public function test_future_imports_use_current_forced_policy_even_when_job_has_old_store_configuration(): void {
        $store=$this->store();$stale=$store->fresh();$offer=$this->offer($store,['external_id'=>'10','reviewed'=>true]);
        app(StoreConditions::class)->configure($store,['default_condition'=>'new']);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>[
            ['title'=>'Artist - Album','handle'=>'album','product_type'=>'CD Usado','variants'=>[['id'=>10,'title'=>'Default Title','price'=>'12000','available'=>true]]]
        ]]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);$run->setRelation('store',$stale);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);$this->assertSame('new',$offer->fresh()->condition);$this->assertTrue($offer->fresh()->reviewed);
        app(StoreConditions::class)->configure($store,['default_condition'=>'used']);
        $run=ScrapeRun::create(['store_id'=>$store->id]);app(CatalogImporter::class)->run($run);$this->assertSame('used',$offer->fresh()->condition);
    }
    public function test_admin_form_exposes_three_modes_and_saving_updates_existing_offers(): void {
        $store=$this->store();$offer=$this->offer($store);$this->actingAs(User::factory()->create());
        $this->get(route('stores.edit',$store))->assertOk()->assertSee('Identificar automáticamente si es nuevo o usado')->assertSee('Todos los productos son nuevos')->assertSee('Todos los productos son usados');
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('validateUrl')->andReturn(['93.184.215.14']);$this->app->instance(StoreClient::class,$client);
        $this->put(route('stores.update',$store),['name'=>$store->name,'url'=>$store->url,'platform'=>'shopify','currency'=>'CLP',
            'default_condition'=>'new','interval_hours'=>48,'active'=>1])->assertRedirect(route('admin'))->assertSessionHasNoErrors();
        $this->assertSame('new',$store->fresh()->default_condition);$this->assertSame('new',$offer->fresh()->condition);
        $this->put(route('offers.update',$offer),['artist'=>'Artist','album'=>'Album','format'=>'cd','condition'=>'used'])->assertSessionHasErrors('condition');
        $this->assertSame('new',$offer->fresh()->condition);
    }
    public function test_automatic_review_does_not_confuse_music_genres_with_condition_and_reads_explicit_descriptions(): void {
        $normalizer=app(\App\Services\ProductNormalizer::class);
        foreach(['New Wave','New Age','Nueva Canción Chilena','Nueva Canci\\u00f3n Chilena','Nueva Ola','Nueva Trova Cubana','Canto Nuevo','The Used'] as $genre)
            $this->assertSame('unknown',$normalizer->parse('Artist - Album',$genre)['condition'],$genre);
        $this->assertSame('used',$normalizer->parse('Artist - Album','New Wave Usado')['condition']);
        $this->assertSame('new',$normalizer->parse('Artist - Album','New Wave','unknown',[],'<p>Vinilo Reeditado, nuevo y sellado.</p>')['condition']);
        $this->assertSame('used',$normalizer->parse('Artist - Album','','unknown',[],'<p>Vinilo usado. Ed de época, Paris.</p>')['condition']);
        $this->assertSame('new',$normalizer->parse('Artist - Album','','unknown',[],'<p>Estado: Nuevo</p>')['condition']);
        $store=$this->store();$offer=$this->offer($store,['condition'=>'new','source_metadata'=>['categories'=>['New Wave']]]);
        $this->assertSame(1,app(StoreConditions::class)->configure($store,['default_condition'=>'unknown'],true));
        $this->assertSame('unknown',$offer->fresh()->condition);
        $legacy=$this->offer($store,['condition'=>'used','source_metadata'=>['identifiers'=>[]]]);
        app(StoreConditions::class)->configure($store,[],true);$this->assertSame('used',$legacy->fresh()->condition);
    }
    public function test_category_policy_matches_exact_normalized_categories_and_reapplies_edited_rules(): void {
        $store=$this->store();
        $new=$this->offer($store,['source_metadata'=>['categories'=>['VINÍLOS NUEVOS']]]);
        $used=$this->offer($store,['source_metadata'=>['categories'=>['Segunda mano']]]);
        $conflict=$this->offer($store,['condition'=>'new','source_metadata'=>['categories'=>['Vinilos nuevos','Segunda mano']]]);
        $unmatched=$this->offer($store,['condition'=>'used','title'=>'Artist - Album Nuevo','source_metadata'=>['categories'=>['Vinilos nuevos especiales'],'tags'=>['Segunda mano']]]);
        $manual=$this->offer($store,['condition'=>'used','reviewed'=>true,'source_metadata'=>['categories'=>['Vinilos nuevos']]]);
        $service=app(StoreConditions::class);
        $this->assertSame(4,$service->configure($store,['default_condition'=>'category','new_condition_categories'=>"vinilos-nuevos\nCD nuevos",'used_condition_categories'=>'Segunda mano']));
        foreach([[$new,'new'],[$used,'used'],[$conflict,'unknown'],[$unmatched,'unknown'],[$manual,'used']] as [$offer,$condition])$this->assertSame($condition,$offer->fresh()->condition);
        $this->assertSame(0,$service->configure($store,[]));
        $service->configure($store,['new_condition_categories'=>'Otra categoría']);
        $this->assertSame('unknown',$new->fresh()->condition);
        $this->assertSame('used',$conflict->fresh()->condition);
        $this->assertDatabaseCount('offer_events',0);
    }
    public function test_category_policy_is_applied_to_future_woocommerce_imports_with_current_rules(): void {
        $store=$this->store();$store->update(['platform'=>'woocommerce']);$stale=$store->fresh();
        app(StoreConditions::class)->configure($store,['default_condition'=>'category','used_condition_categories'=>'de-segunda','category_condition_fallback'=>'new']);
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn([
            ['id'=>12,'name'=>'Artist - Album CD Nuevo','type'=>'simple','categories'=>[['name'=>'Ocasión','slug'=>'de-segunda']],
                'prices'=>['price'=>'12000','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>true],
            ['id'=>13,'name'=>'Artist - Another CD Nuevo','type'=>'simple','tags'=>[['name'=>'de-segunda']],
                'prices'=>['price'=>'14000','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>true],
            ['id'=>14,'name'=>'Artist - Third CD Usado','type'=>'simple','categories'=>[['name'=>'Rock','slug'=>'rock']],
                'prices'=>['price'=>'15000','currency_minor_unit'=>0,'currency_code'=>'CLP'],'is_in_stock'=>true]
        ]);$this->app->instance(StoreClient::class,$client);
        $run=ScrapeRun::create(['store_id'=>$store->id]);$run->setRelation('store',$stale);app(CatalogImporter::class)->run($run);
        $this->assertSame('success',$run->fresh()->status);
        $offer=$store->offers()->where('external_id','12')->firstOrFail();
        $this->assertSame('used',$offer->condition);$this->assertSame(['Ocasión'],$offer->source_metadata['categories']);
        $this->assertSame(['de-segunda'],$offer->source_metadata['category_slugs']);
        $this->assertSame('unknown',$store->offers()->where('external_id','13')->firstOrFail()->condition);
        $this->assertSame('new',$store->offers()->where('external_id','14')->firstOrFail()->condition);
    }
    public function test_admin_saves_category_rules_and_rejects_empty_or_overlapping_rules(): void {
        $store=$this->store();$this->actingAs(User::factory()->create());
        $this->get(route('stores.edit',$store))->assertOk()->assertSee('Identificar condición por categoría')->assertSee('new_condition_categories')->assertSee('used_condition_categories');
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('validateUrl')->andReturn(['93.184.215.14']);$this->app->instance(StoreClient::class,$client);
        $data=['name'=>$store->name,'url'=>$store->url,'platform'=>'woocommerce','currency'=>'CLP','default_condition'=>'category','interval_hours'=>48,'active'=>1];
        $this->put(route('stores.update',$store),$data)->assertSessionHasErrors('new_condition_categories');
        $this->put(route('stores.update',$store),$data+['new_condition_categories'=>'Vinílos nuevos','used_condition_categories'=>'vinilos-nuevos'])->assertSessionHasErrors('used_condition_categories');
        $this->put(route('stores.update',$store),$data+['new_condition_categories'=>'Nuevos','category_condition_fallback'=>'invalid'])->assertSessionHasErrors('category_condition_fallback');
        $this->put(route('stores.update',$store),$data+['new_condition_categories'=>"Nuevos\nSellados",'used_condition_categories'=>'Usados','category_condition_fallback'=>'used'])->assertRedirect(route('admin'))->assertSessionHasNoErrors();
        $this->assertSame('category',$store->fresh()->default_condition);$this->assertSame("Nuevos\nSellados",$store->fresh()->new_condition_categories);
        $this->assertSame('used',$store->fresh()->category_condition_fallback);
        $this->get(route('stores.edit',$store))->assertOk()->assertSee('Cuando no coincida ninguna categoría')->assertSee('value="used" selected',false);
        $this->get(route('admin'))->assertOk()->assertSee('Por categoría');
    }
    public function test_category_fallback_requires_categories_preserves_conflicts_and_manual_edits_and_reapplies_changes(): void {
        $store=$this->store();$service=app(StoreConditions::class);
        $new=$this->offer($store,['source_metadata'=>['categories'=>['Nuevos y Sellados']]]);
        $other=$this->offer($store,['source_metadata'=>['categories'=>['Rock']]]);
        $missing=$this->offer($store,['condition'=>'new','source_metadata'=>['tags'=>['Rock']]]);
        $empty=$this->offer($store,['source_metadata'=>['categories'=>['','  ']]]);
        $conflict=$this->offer($store,['condition'=>'used','source_metadata'=>['categories'=>['Nuevos y Sellados','Usados']]]);
        $manual=$this->offer($store,['condition'=>'new','reviewed'=>true,'source_metadata'=>['categories'=>['Rock']]]);
        $service->configure($store,['default_condition'=>'category','new_condition_categories'=>'Nuevos y Sellados','used_condition_categories'=>'Usados','category_condition_fallback'=>'used']);
        foreach([[$new,'new'],[$other,'used'],[$missing,'unknown'],[$empty,'unknown'],[$conflict,'unknown'],[$manual,'new']] as [$offer,$expected])$this->assertSame($expected,$offer->fresh()->condition);
        $this->assertSame(1,$service->configure($store,['category_condition_fallback'=>'new']));
        $this->assertSame('new',$other->fresh()->condition);$this->assertSame('unknown',$missing->fresh()->condition);$this->assertSame('unknown',$conflict->fresh()->condition);
        $this->assertSame(1,$service->configure($store,['category_condition_fallback'=>'unknown']));
        $this->assertSame('unknown',$other->fresh()->condition);
        $this->assertDatabaseCount('offer_events',0);$this->assertDatabaseCount('offer_observations',0);
    }
}
