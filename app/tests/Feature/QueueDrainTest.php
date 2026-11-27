<?php
namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\{Store,ScrapeRun};
use App\Jobs\ScrapeStore;
use App\Services\CatalogImporter;
use Tests\TestCase;

class QueueDrainTest extends TestCase {
    use RefreshDatabase;
    public function test_actual_database_queue_is_processed_without_contacting_an_external_store(): void {
        config(['queue.default'=>'database']);
        $store=Store::create(['name'=>'Shop','url'=>'https://shop.example','platform'=>'shopify','currency'=>'CLP']);
        $run=ScrapeRun::create(['store_id'=>$store->id,'status'=>'queued']);
        $importer=\Mockery::mock(CatalogImporter::class);
        $importer->shouldReceive('run')->once()->andReturnUsing(fn($current)=>$current->update(['status'=>'success','finished_at'=>now()]));
        $this->app->instance(CatalogImporter::class,$importer);
        Queue::connection('database')->push(new ScrapeStore($run->id));
        $this->artisan('queue:drain',['--seconds'=>'1'])->assertSuccessful();
        $this->assertSame('success',$run->fresh()->status);$this->assertDatabaseCount('jobs',0);
    }
    public function test_worker_uses_bounded_runtime_and_releases_its_lock(): void {
        $calls=[];
        Artisan::command('queue:work {--stop-when-empty} {--max-time=} {--sleep=} {--tries=} {--timeout=} {--memory=}',function()use(&$calls){$calls[]=$this->options();return 0;});
        $this->artisan('queue:drain')->assertSuccessful();
        $this->artisan('queue:drain',['--seconds'=>'75'])->assertSuccessful();
        $this->assertCount(2,$calls);$this->assertTrue($calls[0]['stop-when-empty']);
        $this->assertSame(50,$calls[0]['max-time']);$this->assertSame(75,$calls[1]['max-time']);
        $this->assertSame(1800,$calls[0]['timeout']);$this->assertSame(128,$calls[0]['memory']);
    }
    public function test_concurrent_worker_skips_processing_without_waiting(): void {
        $calls=0;
        Artisan::command('queue:work',function()use(&$calls){$calls++;return 0;});
        $lock=fopen(storage_path('framework/queue-worker.lock'),'c');$this->assertTrue(flock($lock,LOCK_EX|LOCK_NB));
        try {$this->artisan('queue:drain')->expectsOutput('Ya hay un trabajador procesando la cola.')->assertSuccessful();$this->assertSame(0,$calls);}
        finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function test_invalid_duration_does_not_start_a_worker(): void {
        foreach(['0','-1','3601','invalid'] as $value)$this->artisan('queue:drain',['--seconds'=>$value])->assertFailed();
    }
}
