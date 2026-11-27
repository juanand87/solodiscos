<?php
namespace App\Services;
use App\Models\{Album,Offer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};

class EngagementTracker {
    private function human(Request $r): bool {
        if(Auth::guard('web')->check() || Auth::guard('store')->check())return false;
        $agent=(string)$r->userAgent();
        if(!$agent || preg_match('/bot|crawler|spider|headless|slurp|preview|facebookexternalhit|uptime|monitor|curl|wget/i',$agent))return false;
        return !preg_match('/prefetch|prerender/i',implode(' ',[$r->header('Purpose',''),$r->header('Sec-Purpose',''),$r->header('X-Moz','')]));
    }
    private function record(Request $r,string $identity,int $seconds,array $rows): void {
        try{
            if(!$this->human($r) || !$r->hasSession() || !Cache::add('engagement:'.$identity.':'.hash_hmac('sha256',$r->session()->getId(),config('app.key')),1,$seconds))return;
            // Only temporary hashes are cached. Raw IP, user agent and visitor IDs are never stored in metrics.
            $budget='engagement-budget:'.hash_hmac('sha256',(string)$r->ip(),config('app.key'));
            Cache::add($budget,0,60);if(Cache::increment($budget)>120)return;
            DB::transaction(function()use($rows){foreach($rows as $row){
                $row+=['day'=>now()->toDateString(),'store_id'=>null,'offer_id'=>null];
                $key=hash('sha256',implode('|',[$row['day'],$row['kind'],$row['album_id'],$row['format'],$row['store_id']??0,$row['offer_id']??0]));
                DB::table('engagement_days')->insertOrIgnore($row+['metric_key'=>$key,'total'=>0]);
                DB::table('engagement_days')->where('metric_key',$key)->increment('total');
            }});
        }catch(\Throwable $e){
            // Measurement must never interrupt browsing, including during a partial deployment.
        }
    }
    public function album(Request $r,Album $album,string $format): void {
        $rows=[['kind'=>'album_view','album_id'=>$album->id,'format'=>$format]];
        foreach($album->offers->unique('store_id') as $offer)$rows[]=['kind'=>'product_view','album_id'=>$album->id,'format'=>$format,'store_id'=>$offer->store_id,'offer_id'=>$offer->id];
        $this->record($r,'view:'.$album->id.':'.$format,1800,$rows);
    }
    public function click(Request $r,Offer $offer): void {
        $this->record($r,'click:'.$offer->id,60,[['kind'=>'click','album_id'=>$offer->album_id,'format'=>$offer->format,'store_id'=>$offer->store_id,'offer_id'=>$offer->id]]);
    }
}
