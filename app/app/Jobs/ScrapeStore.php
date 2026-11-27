<?php
namespace App\Jobs;
use App\Models\ScrapeRun;
use App\Services\CatalogImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;
class ScrapeStore implements ShouldQueue {
    use Queueable;
    public int $timeout=1800;
    public int $tries=1;
    public function __construct(public int $runId) {}
    public function handle(CatalogImporter $importer): void { $run=ScrapeRun::findOrFail($this->runId); if ($run->status==='queued') $importer->run($run); }
    public function failed(?Throwable $e): void { ScrapeRun::whereKey($this->runId)->update(['status'=>'failed','finished_at'=>now(),'message'=>'Trabajo interrumpido o tiempo máximo excedido. Puedes volver a ejecutarlo.']); }
}
