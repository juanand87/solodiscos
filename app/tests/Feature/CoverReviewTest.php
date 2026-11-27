<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\CoverImageFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use App\Models\{Store,Offer,Album,User,CoverScan,CoverMatch,CoverFingerprint};
use App\Services\{StoreClient,CoverScanner,CoverReview,CoverCandidates,ProductNormalizer};

class CoverReviewTest extends TestCase {
    use RefreshDatabase,CoverImageFixtures;
    private string $thumbs;
    protected function setUp(): void {parent::setUp();$this->thumbs=sys_get_temp_dir().'/solodiscos-phash-'.bin2hex(random_bytes(6));config(['covers.thumbnail_directory'=>$this->thumbs]);}
    protected function tearDown(): void {foreach(glob($this->thumbs.'/*.jpg')?:[] as $file)unlink($file);if(is_dir($this->thumbs))rmdir($this->thumbs);parent::tearDown();}
    private function offer(string $title='25 CAN',string $format='cd',string $artist='Adele',array $extra=[]): Offer {
        $store=Store::firstOrCreate(['url'=>'https://store.example'],['name'=>'Tienda','platform'=>'woocommerce','currency'=>'CLP']);
        $n=app(ProductNormalizer::class);$album=Album::firstOrCreate(['match_key'=>$n->key($artist,$title)],['artist'=>$artist,'title'=>$title]);
        $id=uniqid();return Offer::create($extra+['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>$id,'title'=>$artist.' - '.$title,'format'=>$format,'condition'=>'new','availability'=>'in_stock','checked_at'=>now(),'price'=>19990,'currency'=>'CLP','url'=>$store->url.'/product/'.$id,'image'=>'https://cdn.example/'.$id.'.png']);
    }
    private function scanner(array $urls): CoverScan {
        $client=\Mockery::mock(StoreClient::class);foreach($urls as $url)$client->shouldReceive('imageBytes')->with($url)->once()->andReturn($this->coverBytes());
        $this->app->instance(StoreClient::class,$client);$scan=CoverScan::create(['image_limit'=>20]);app(CoverScanner::class)->run($scan);return $scan->fresh();
    }
    private function pair(): array {$a=$this->offer('25');$b=$this->offer();$this->scanner([$a->image,$b->image]);return [$a,$b,CoverMatch::firstOrFail()];}
    public function test_scanner_only_suggests_same_artist_format_distinct_album_and_preserves_catalog(): void {
        $a=$this->offer('25');$b=$this->offer();$this->offer('30');$this->offer('25 CAN','vinyl');$this->offer('25','cd','Different Artist');
        $scan=$this->scanner([$a->image,$b->image]);$this->assertSame('success',$scan->status);$this->assertDatabaseCount('cover_matches',1);
        $this->assertSame(0,CoverMatch::first()->distance);$this->assertSame($b->album_id,$b->fresh()->album_id);$this->assertFalse($b->fresh()->reviewed);
        $this->assertSame(2,$scan->downloaded);$this->assertSame(1,$scan->suggestions);
    }
    public function test_cache_and_rejected_decision_are_reused_without_downloading_or_proposing_again(): void {
        [$a,$b,$match]=$this->pair();$user=User::factory()->create();app(CoverReview::class)->reject($match,$user->id);
        $client=\Mockery::mock(StoreClient::class);$client->shouldNotReceive('imageBytes');$this->app->instance(StoreClient::class,$client);
        $scan=CoverScan::create(['image_limit'=>20]);app(CoverScanner::class)->run($scan);
        $this->assertDatabaseCount('cover_matches',1);$this->assertSame('rejected',$match->fresh()->status);$this->assertSame(0,$scan->fresh()->downloaded);
    }
    public function test_admin_approval_only_moves_automatic_offers_in_selected_format_and_can_be_undone(): void {
        [$a,$b,$match]=$this->pair();$user=User::factory()->create();$old=$b->album_id;
        $manual=$this->offer('25 CAN','cd','Adele',['reviewed'=>true]);$vinyl=$this->offer('25 CAN','vinyl');$stock=$b->availability;$price=$b->price;$checked=$b->checked_at;
        $this->actingAs($user)->post(route('covers.accept',$match),['target'=>'left','confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $b->refresh();$this->assertSame($a->album_id,$b->album_id);$this->assertTrue($b->reviewed);$this->assertSame($stock,$b->availability);$this->assertEquals($price,$b->price);$this->assertTrue($checked->eq($b->checked_at));
        $this->assertSame($old,$manual->fresh()->album_id);$this->assertSame($old,$vinyl->fresh()->album_id);
        $this->actingAs($user)->post(route('covers.undo',$match))->assertRedirect()->assertSessionHasNoErrors();$b->refresh();$this->assertSame($old,$b->album_id);$this->assertFalse($b->reviewed);
    }
    public function test_changed_image_or_numeric_title_never_allows_stale_approval(): void {
        [$a,$b,$match]=$this->pair();$b->update(['image'=>'https://cdn.example/changed.png']);
        $this->actingAs(User::factory()->create())->post(route('covers.accept',$match),['target'=>'left','confirm'=>1])->assertSessionHasErrors('cover');
        $this->assertSame('pending',$match->fresh()->status);$this->assertFalse($b->fresh()->reviewed);
        $this->assertTrue(app(CoverCandidates::class)->numericConflict('25','30'));
    }
    public function test_undo_does_not_overwrite_a_later_manual_classification(): void {
        [$a,$b,$match]=$this->pair();$user=User::factory()->create();app(CoverReview::class)->accept($match,'left',$user->id);
        $b->refresh()->update(['condition'=>'used']);
        $this->actingAs($user)->post(route('covers.undo',$match))->assertSessionHasErrors('cover');$this->assertSame('used',$b->fresh()->condition);
    }
    public function test_admin_routes_are_private_progress_is_readonly_and_duplicate_scans_are_not_queued(): void {
        $this->get(route('covers.index'))->assertRedirect('/login');$this->getJson(route('covers.progress'))->assertUnauthorized();
        Queue::fake();$this->actingAs(User::factory()->create())->get(route('covers.index'))->assertOk()->assertSee('Compara las portadas');
        $this->post(route('covers.scan'),['artist'=>'Adele','limit'=>25])->assertRedirect();
        $this->post(route('covers.scan'),['artist'=>'Adele','limit'=>25])->assertRedirect();Queue::assertPushed(\App\Jobs\ScanCovers::class,1);$this->assertDatabaseCount('cover_scans',1);
        $this->getJson(route('covers.progress'))->assertOk()->assertJsonPath('scan.status','queued');$this->assertDatabaseCount('cover_matches',0);
    }
    public function test_reviewer_can_approve_without_checkbox_and_view_cached_images(): void {
        [$a,$b,$match]=$this->pair();$this->actingAs(User::factory()->create());
        $this->get(route('covers.index'))->assertOk()->assertSee('25 CAN')->assertSee('Distancia pHash')->assertDontSee('Confirmo que son el mismo álbum y formato.');
        $this->get(route('covers.thumbnail',CoverFingerprint::first()))->assertOk()->assertHeader('Content-Type','image/jpeg');
        $this->post(route('covers.accept',$match),['target'=>'left'])->assertSessionHasNoErrors();$this->assertTrue($b->fresh()->reviewed);
    }
    public function test_cover_pagination_allows_up_to_500_and_bulk_token_accepts_large_pages(): void {
        [$a,$b,$match]=$this->pair();$this->actingAs(User::factory()->create());
        for($i=0;$i<500;$i++){$copy=$match->replicate();$copy->signature=hash('sha256','page-'.$i);$copy->save();}
        $this->assertSame(100,$this->get(route('covers.index'))->assertOk()->viewData('matches')->perPage());
        $page=$this->get(route('covers.index',['per_page'=>500,'artist'=>'Adele']))->assertOk()->viewData('matches');
        $this->assertCount(500,$page->items());$this->assertSame(501,$page->total());$this->assertStringContainsString('per_page=500',$page->url(2));$this->assertStringContainsString('artist=Adele',$page->url(2));
        $this->get(route('covers.index',['per_page'=>600]))->assertSessionHasErrors('per_page');
        $token=\Illuminate\Support\Facades\Crypt::encryptString(json_encode(['ids'=>$page->pluck('id')->all(),'artist'=>'Adele','per_page'=>500]));
        $targets=$page->take(13)->mapWithKeys(fn($m)=>[$m->id=>'left'])->all();
        $this->post(route('covers.accept-page'),['page_token'=>$token,'targets'=>$targets,'confirm'=>1])->assertSessionHasNoErrors()->assertRedirect(route('covers.index',['status'=>'pending','artist'=>'Adele','per_page'=>500]));
    }
    public function test_image_fetcher_rejects_private_addresses_without_making_requests(): void {
        $this->expectException(\RuntimeException::class);(new StoreClient)->imageBytes('http://127.0.0.1/private.jpg');
    }
    private function pageToken(array $ids): string {
        return \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['ids'=>$ids,'artist'=>'Adele']));
    }
    public function test_page_approval_only_approves_submitted_visible_matches_and_can_be_undone(): void {
        [$a,$b,$match]=$this->pair();$unseen=$match->replicate();$unseen->signature=hash('sha256','another-page');$unseen->save();
        $this->actingAs(User::factory()->create());
        $this->post(route('covers.accept-page'),['page_token'=>$this->pageToken([$match->id]),'targets'=>[$match->id=>'left'],'confirm'=>1])
            ->assertRedirect(route('covers.index',['status'=>'pending','artist'=>'Adele','per_page'=>100]))->assertSessionHasNoErrors();
        $this->assertSame('accepted',$match->fresh()->status);$this->assertSame('obsolete',$unseen->fresh()->status);
        $this->assertSame($a->album_id,$b->fresh()->album_id);
        $this->post(route('covers.undo',$match))->assertSessionHasNoErrors();$this->assertSame($match->right_album_id,$b->fresh()->album_id);
    }
    public function test_page_approval_rejects_hidden_matches_tampered_token_and_missing_confirmation(): void {
        [$a,$b,$match]=$this->pair();$this->actingAs(User::factory()->create());
        $this->post(route('covers.accept-page'),['page_token'=>$this->pageToken([]),'targets'=>[$match->id=>'left'],'confirm'=>1])->assertSessionHasErrors('cover');
        $this->post(route('covers.accept-page'),['page_token'=>'invalid','targets'=>[$match->id=>'left'],'confirm'=>1])->assertSessionHasErrors('cover');
        $this->post(route('covers.accept-page'),['page_token'=>$this->pageToken([$match->id]),'targets'=>[$match->id=>'left']])->assertSessionHasErrors('confirm');
        $this->assertSame('pending',$match->fresh()->status);$this->assertSame($match->right_album_id,$b->fresh()->album_id);
    }
    public function test_page_approval_skips_changed_matches_and_respects_selected_right_target(): void {
        [$a,$b,$stale]=$this->pair();
        $c=$this->offer('25','cd','Other');$d=$this->offer('25 CAN','cd','Other');$this->scanner([$c->image,$d->image]);
        $b->update(['image'=>'https://cdn.example/changed.png']);
        $match=CoverMatch::where('left_offer_id',$c->id)->firstOrFail();$this->actingAs(User::factory()->create());
        $this->post(route('covers.accept-page'),['page_token'=>$this->pageToken([$stale->id,$match->id]),
            'targets'=>[$stale->id=>'left',$match->id=>'right'],'confirm'=>1])->assertSessionHasNoErrors()->assertSessionHas('status',fn($text)=>str_contains($text,'1 propuestas aprobadas') && str_contains($text,'1 propuestas omitidas'));
        $this->assertSame('obsolete',$stale->fresh()->status);$this->assertSame('accepted',$match->fresh()->status);
        $this->assertSame($d->album_id,$c->fresh()->album_id);
    }
    public function test_outdated_pending_matches_are_archived_before_pagination_without_changing_offers(): void {
        [$a,$b,$match]=$this->pair();$old=$b->album_id;$b->update(['image'=>'https://cdn.example/updated.png']);
        $this->actingAs(User::factory()->create())->get(route('covers.index'))->assertOk()->assertDontSee('Los datos de esta propuesta cambiaron.');
        $this->assertSame('obsolete',$match->fresh()->status);$this->assertSame($old,$b->fresh()->album_id);$this->assertFalse($b->fresh()->reviewed);
        $this->get(route('covers.index',['status'=>'obsolete']))->assertOk()->assertSee('25 CAN');
        $this->assertSame(0,CoverMatch::retireStalePending());
    }
}
