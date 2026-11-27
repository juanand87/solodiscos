<?php
namespace App\Jobs;
use App\Models\CoverScan;
use App\Services\CoverScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class ScanCovers implements ShouldQueue {
    use Queueable;
    public int $timeout=900;public int $tries=3;
    public function __construct(public int $runId,public ?int $expectedBatch=null){}
    public function backoff(): array {return [30,60,120];}
    public function handle(CoverScanner $scanner): void {
        $run=CoverScan::findOrFail($this->runId);if(!in_array($run->status,['queued','running']))return;
        $more=$scanner->run($run,$this->expectedBatch);
        if($more===null){$this->release(15);return;}
        if($more)self::dispatch($run->id,(int)$run->fresh()->batch_cursor)->delay(now()->addSeconds(3));
    }
    public function failed(?\Throwable $e): void {CoverScan::whereKey($this->runId)->update(['status'=>'failed','message'=>'Comparación interrumpida. Puedes volver a ejecutar; se conserva la caché.','finished_at'=>now()]);}
}
