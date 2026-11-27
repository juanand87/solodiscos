<?php
namespace App\Services;
use App\Models\{CoverScan,CoverScanImage,CoverScanPair,CoverMatch};
use Illuminate\Support\Facades\Cache;

class CoverScanner {
    public function __construct(private CoverImages $images,private CoverCandidates $candidates,private CoverScanPlanner $planner){}
    // true: continue; false: finished/stale; null: worker lock is busy.
    public function run(CoverScan $run,?int $expectedBatch=null): ?bool {
        $lock=Cache::lock('cover-scan-running',900);if(!$lock->get())return null;
        try {
            $run->refresh();
            if(!in_array($run->status,['queued','running']) || ($expectedBatch!==null && (int)$run->batch_cursor!==$expectedBatch))return false;
            $run->update(['status'=>'running','started_at'=>$run->started_at??now(),'heartbeat_at'=>now()]);
            $this->planner->prepare($run);
            $deadline=microtime(true)+max(1,(int)config('covers.batch_seconds',90));$newDownloads=0;
            foreach(CoverScanImage::where('cover_scan_id',$run->id)->where('status','pending')->orderBy('id')->limit(500)->get() as $task){
                if(microtime(true)>$deadline)break;
                $cover=$this->images->cached($task->url);$downloaded=false;
                if(!$cover){if($newDownloads>=$run->image_limit)break;$newDownloads++;$downloaded=true;$cover=$this->images->fetch($task->url);usleep((int)config('covers.download_pause_us',500000));}
                $task->update(['status'=>$cover->status==='ready'?'ready':'failed','cover_fingerprint_id'=>$cover->id,'downloaded'=>$downloaded,'error'=>$cover->error]);
                $run->increment('processed');if($downloaded)$run->increment('downloaded');if($cover->status!=='ready')$run->increment('errors');
                $run->update(['heartbeat_at'=>now(),'message'=>'Lote '.($run->batch_count+1).': calculando portadas. Avance guardado automáticamente.']);
            }
            $pairs=CoverScanPair::where('cover_scan_id',$run->id)->where('status','pending')
                ->whereHas('leftImage',fn($q)=>$q->where('status','!=','pending'))->whereHas('rightImage',fn($q)=>$q->where('status','!=','pending'))
                ->with(['leftImage.fingerprint','rightImage.fingerprint','leftOffer.album','rightOffer.album'])->orderBy('id')->limit((int)config('covers.pairs_per_batch',1000))->get();
            foreach($pairs as $pair){
                $a=$pair->leftOffer;$b=$pair->rightOffer;$left=$pair->leftImage;$right=$pair->rightImage;
                $valid=$a && $b && $a->album_id===$pair->left_album_id && $b->album_id===$pair->right_album_id && $a->format===$pair->format && $b->format===$pair->format
                    && $a->image===$left->url && $b->image===$right->url && $this->candidates->compatible($a,$b);
                $match=null;
                if($valid && $left->status==='ready' && $right->status==='ready')$match=$this->candidates->suggest($a,$b,$left->fingerprint,$right->fingerprint);
                $pair->update(['status'=>$valid && $left->status==='ready' && $right->status==='ready'?'compared':'skipped']);
                if($match && $match->wasRecentlyCreated)$match->update(['cover_scan_id'=>$run->id]);
            }
            $more=CoverScanImage::where('cover_scan_id',$run->id)->where('status','pending')->exists() || CoverScanPair::where('cover_scan_id',$run->id)->where('status','pending')->exists();
            $processed=CoverScanImage::where('cover_scan_id',$run->id)->where('status','!=','pending')->count();
            $compared=CoverScanPair::where('cover_scan_id',$run->id)->where('status','compared')->count();$skipped=CoverScanPair::where('cover_scan_id',$run->id)->where('status','skipped')->count();
            $suggestions=CoverMatch::where('cover_scan_id',$run->id)->count();$errors=CoverScanImage::where('cover_scan_id',$run->id)->where('status','failed')->count();
            $run->update(['status'=>$more?'running':'success','processed'=>$processed,'downloaded'=>CoverScanImage::where('cover_scan_id',$run->id)->where('downloaded',true)->count(),
                'suggestions'=>$suggestions,'errors'=>$errors,'compared_pairs'=>$compared,'skipped_pairs'=>$skipped,'batch_cursor'=>$run->batch_cursor+1,'batch_count'=>$run->batch_count+1,
                'heartbeat_at'=>now(),'finished_at'=>$more?null:now(),'message'=>$more?
                    'Lotes automáticos en curso: '.($compared+$skipped).'/'.$run->total_pairs.' pares resueltos. El siguiente lote continuará solo.':
                    "Recorrido completo: $compared pares comparados, $skipped omitidos, $suggestions propuestas generadas para revisión y $errors imágenes no utilizables."]);
            return $more;
        }finally{$lock->release();}
    }
}
