<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,Offer,ScrapeRun,User};
use App\Services\{StoreClient,CatalogImporter};
class BookExclusionTest extends TestCase {
    use RefreshDatabase;
    public function test_book_stays_internal_and_cannot_be_published_by_manual_cd_selection(): void {
        $store=Store::create(['name'=>'Needle','url'=>'https://needle.cl','platform'=>'shopify','logo_checked_at'=>now()]);
        $product=['title'=>'33 1/3 Parallel Lines - Kembrew McLeod','handle'=>'33-13-parallel-lines-kembrew-mcleod','product_type'=>'Libro','tags'=>['CD','Rock'],'body_html'=>'<p>Formato: Tapa blanda (Paperback)</p>','variants'=>[['id'=>45665233666105,'title'=>'Default Title','price'=>'17900','available'=>true]]];
        $client=\Mockery::mock(StoreClient::class);$client->shouldReceive('get')->andReturn(['products'=>[$product]]);$this->app->instance(StoreClient::class,$client);
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));
        $offer=Offer::firstOrFail();$this->assertFalse($offer->isPublished());$this->assertSame('unknown',$offer->format);$this->assertNotNull($offer->excluded_reason);$this->assertSame('Libro',$offer->source_metadata['product_type']);
        $this->assertDatabaseCount('albums',0);$this->get('/')->assertOk()->assertDontSee('Kembrew');
        $this->actingAs(User::factory()->create())->get('/admin/ofertas/'.$offer->id.'/editar')->assertOk()->assertSee('Excluido del catálogo');
        $this->put('/admin/ofertas/'.$offer->id,['artist'=>'Blondie','album'=>'Parallel Lines','format'=>'cd','condition'=>'new'])->assertSessionHasErrors('format');
        app(CatalogImporter::class)->run(ScrapeRun::create(['store_id'=>$store->id]));$this->assertFalse($offer->fresh()->isPublished());$this->assertDatabaseCount('offers',1);
    }
    public function test_audit_unpublishes_historical_book_without_downgrading_real_records(): void {
        $store=Store::create(['name'=>'Tienda','url'=>'https://example.com','platform'=>'shopify']);
        $base=['store_id'=>$store->id,'url'=>'https://example.com/product','price'=>17900,'currency'=>'CLP','format'=>'cd','availability'=>'in_stock','checked_at'=>now()];
        $book=Offer::create($base+['external_id'=>'book','title'=>'33 1/3 Parallel Lines - Kembrew McLeod','description'=>'En este volumen de la colección 33 1/3, Kembrew McLeod examina la historia.']);
        $disc=Offer::create($base+['external_id'=>'disc','title'=>'Iron Maiden - The Book Of Souls','description'=>'Incluye un booklet con las letras.']);
        $this->artisan('catalog:audit-products')->assertSuccessful();
        $this->assertNotNull($book->fresh()->excluded_reason);$this->assertSame('unknown',$book->fresh()->format);
        $this->assertNull($disc->fresh()->excluded_reason);$this->assertSame('cd',$disc->fresh()->format);
    }
}
