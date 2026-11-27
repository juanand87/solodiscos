<?php
namespace App\Services;
use App\Models\CoverScan;
use App\Jobs\ScanCovers;
use Illuminate\Support\Facades\{Cache,DB};
class CoverScanDispatcher {
    public function start(?string $artist,int $limit): ?CoverScan {
        return Cache::lock('cover-scan-dispatch',10)->block(3,function()use($artist,$limit){
            return DB::transaction(function()use($artist,$limit){
                if(CoverScan::whereIn('status',['queued','running'])->exists())return null;
                $scan=CoverScan::create(['artist'=>$artist?:null,'image_limit'=>max(1,min(500,$limit))]);
                ScanCovers::dispatch($scan->id,0)->afterCommit();return $scan;
            });
        });
    }
    public function recover(): int {
        return Cache::lock('cover-scan-dispatch',10)->block(3,function(){
            $count=0;
            $runs=CoverScan::whereIn('status',['queued','running'])->where(fn($q)=>$q->where('heartbeat_at','<',now()->subMinutes(10))
                ->orWhere(fn($q)=>$q->whereNull('heartbeat_at')->where('created_at','<',now()->subMinutes(10))))->get();
            foreach($runs as $run){
                // Existing database jobs (including a reserved retry) must be allowed to finish first.
                $exists=false;
                foreach(DB::table('jobs')->select('payload')->get() as $job){
                    $payload=json_decode($job->payload,true);
                    if(($payload['data']['commandName']??null)===ScanCovers::class && str_contains($payload['data']['command']??'','s:5:"runId";i:'.$run->id.';')){$exists=true;break;}
                }
                if($exists)continue;
                ScanCovers::dispatch($run->id,(int)$run->batch_cursor);
                $run->update(['heartbeat_at'=>now(),'message'=>'Reanudando automáticamente el siguiente lote desde el último punto guardado.']);$count++;
            }
            return $count;
        });
    }
}
