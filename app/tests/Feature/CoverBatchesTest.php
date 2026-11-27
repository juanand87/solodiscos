<?php
namespace Tests\Feature;
use Tests\TestCase;
use Tests\Support\CoverImageFixtures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Queue,DB};
use App\Jobs\ScanCovers;
use App\Models\{Store,Offer,Album,CoverScan,CoverMatch,CoverScanPair};
use App\Services\{StoreClient,CoverScanner,CoverScanDispatcher,ProductNormalizer};

class CoverBatchesTest extends TestCase {
    use RefreshDatabase,CoverImageFixtures;
    private string $thumbs;
    protected function setUp(): void {
        parent::setUp();$this->thumbs=sys_get_temp_dir().'/solodiscos-batches-'.bin2hex(random_bytes(6));
        config(['covers.thumbnail_directory'=>$this->thumbs,'covers.download_pause_us'=>0,'covers.batch_seconds'=>30]);
    }
    protected function tearDown(): void {
        foreach(glob($this->thumbs.'/*.jpg')?:[] as $file)unlink($file);
        if(is_dir($this->thumbs))rmdir($this->thumbs);parent::tearDown();
    }
    private function offer(string $title,array $extra=[]): Offer {
        $store=Store::firstOrCreate(['url'=>'https://store.example'],['name'=>'Tienda','platform'=>'woocommerce','currency'=>'CLP']);
        $album=Album::firstOrCreate(['match_key'=>app(ProductNormalizer::class)->key('Adele',$title)],['artist'=>'Adele','title'=>$title]);
        $id=uniqid();return Offer::create($extra+['store_id'=>$store->id,'album_id'=>$album->id,'external_id'=>$id,'title'=>'Adele - '.$title,
            'format'=>'cd','condition'=>'new','availability'=>'in_stock','checked_at'=>now(),'price'=>19990,'currency'=>'CLP',
            'url'=>$store->url.'/product/'.$id,'image'=>'https://cdn.example/'.$id.'.png']);
    }
    private function images(array $offers): void {
        $client=\Mockery::mock(StoreClient::class);
        foreach($offers as $offer)$client->shouldReceive('imageBytes')->with($offer->image)->once()->andReturn($this->coverBytes());
        $this->app->instance(StoreClient::class,$client);
    }
    public function test_jobs_chain_batches_and_old_generation_cannot_repeat_work(): void {
        $a=$this->offer('25');$b=$this->offer('25 CAN');$this->images([$a,$b]);Queue::fake();
        $scan=CoverScan::create(['image_limit'=>1]);$scanner=app(CoverScanner::class);
        (new ScanCovers($scan->id,0))->handle($scanner);$scan->refresh();
        $this->assertSame('running',$scan->status);$this->assertSame(1,$scan->processed);$this->assertSame(2,$scan->total_images);
        $this->assertSame(1,$scan->batch_count);$this->assertDatabaseCount('cover_matches',0);
        Queue::assertPushed(ScanCovers::class,fn($job)=>$job->runId===$scan->id && $job->expectedBatch===1);
        (new ScanCovers($scan->id,0))->handle($scanner);$this->assertSame(1,$scan->fresh()->batch_count);
        (new ScanCovers($scan->id,1))->handle($scanner);$scan->refresh();
        $this->assertSame('success',$scan->status);$this->assertSame(2,$scan->processed);$this->assertSame(2,$scan->downloaded);
        $this->assertSame(1,$scan->compared_pairs);$this->assertSame(1,$scan->suggestions);$this->assertSame('pending',CoverMatch::first()->status);
        $this->assertSame($b->album_id,$b->fresh()->album_id);$this->assertFalse($b->fresh()->reviewed);
        Queue::assertPushed(ScanCovers::class,1);
    }
    public function test_all_distinct_covers_and_out_of_stock_are_planned_and_pair_limit_continues(): void {
        $offers=[$this->offer('25'),$this->offer('25'),$this->offer('25'),$this->offer('25 CAN',['availability'=>'out_of_stock'])];
        $this->images($offers);config(['covers.pairs_per_batch'=>1]);$scan=CoverScan::create(['image_limit'=>20]);$scanner=app(CoverScanner::class);
        $this->assertTrue($scanner->run($scan,0));$scan->refresh();
        $this->assertSame(4,$scan->total_images);$this->assertSame(3,$scan->total_pairs);$this->assertSame(1,$scan->compared_pairs);
        $this->assertTrue($scanner->run($scan,1));$this->assertFalse($scanner->run($scan,2));$scan->refresh();
        $this->assertSame(3,$scan->compared_pairs);$this->assertSame(3,$scan->suggestions);$this->assertSame(4,$scan->downloaded);
        $this->assertSame('out_of_stock',$offers[3]->fresh()->availability);
    }
    public function test_failed_image_resolves_pair_without_losing_following_batches(): void {
        $a=$this->offer('25');$b=$this->offer('25 CAN');$client=\Mockery::mock(StoreClient::class);
        $client->shouldReceive('imageBytes')->with($a->image)->once()->andThrow(new \RuntimeException('Unavailable'));
        $client->shouldReceive('imageBytes')->with($b->image)->once()->andReturn($this->coverBytes());$this->app->instance(StoreClient::class,$client);
        $scan=CoverScan::create(['image_limit'=>1]);$scanner=app(CoverScanner::class);
        $this->assertTrue($scanner->run($scan,0));$this->assertFalse($scanner->run($scan,1));$scan->refresh();
        $this->assertSame('success',$scan->status);$this->assertSame(1,$scan->errors);$this->assertSame(1,$scan->skipped_pairs);$this->assertSame(0,$scan->suggestions);
    }
    public function test_changed_offer_after_planning_is_skipped(): void {
        $a=$this->offer('25');$b=$this->offer('25 CAN');$this->images([$a,$b]);$scan=CoverScan::create(['image_limit'=>1]);$scanner=app(CoverScanner::class);
        $scanner->run($scan,0);$b->update(['format'=>'vinyl']);$scanner->run($scan,1);
        $this->assertSame('skipped',CoverScanPair::first()->status);$this->assertDatabaseCount('cover_matches',0);
    }
    public function test_scheduler_recovers_lost_continuation_at_saved_cursor_only_once(): void {
        Queue::fake();$scan=CoverScan::create(['image_limit'=>1,'status'=>'running','batch_cursor'=>7,'heartbeat_at'=>now()->subMinutes(11)]);
        $dispatcher=app(CoverScanDispatcher::class);$this->assertSame(1,$dispatcher->recover());$this->assertSame(0,$dispatcher->recover());
        Queue::assertPushed(ScanCovers::class,fn($job)=>$job->runId===$scan->id && $job->expectedBatch===7);
        $scan->update(['status'=>'success','heartbeat_at'=>now()->subMinutes(11)]);$this->assertSame(0,$dispatcher->recover());
    }
    public function test_reserved_job_prevents_scheduler_from_queuing_duplicate(): void {
        $scan=CoverScan::create(['image_limit'=>1,'status'=>'running','heartbeat_at'=>now()->subMinutes(11)]);
        $payload=json_encode(['data'=>['commandName'=>ScanCovers::class,'command'=>serialize(new ScanCovers($scan->id,0))]]);
        DB::table('jobs')->insert(['queue'=>'default','payload'=>$payload,'attempts'=>1,'reserved_at'=>time(),'available_at'=>time(),'created_at'=>time()]);
        Queue::fake();$this->assertSame(0,app(CoverScanDispatcher::class)->recover());Queue::assertNothingPushed();
    }
}
