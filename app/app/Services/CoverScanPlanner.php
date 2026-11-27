<?php
namespace App\Services;
use App\Models\{Offer,CoverScan,CoverScanImage,CoverScanPair,CoverMatch};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CoverScanPlanner {
    public function __construct(private CoverCandidates $candidates){}
    public function prepare(CoverScan $run): void {
        if($run->planned_at)return;
        $run->update(['message'=>'Preparando el recorrido completo por artista y formato; incluye agotados.','heartbeat_at'=>now()]);
        CoverMatch::retireStalePending();
        $query=Offer::whereNull('excluded_reason')->whereNotNull('album_id')->whereIn('format',['cd','vinyl'])->whereNotNull('image')
            ->whereHas('store',fn($q)=>$q->where('active',true))->with('album')->orderBy('id');
        if($run->artist)$query->whereHas('album',fn($q)=>$q->where('artist','like','%'.$run->artist.'%'));
        $groups=[];
        foreach($query->get(['id','album_id','store_id','format','image','title','excluded_reason','reviewed']) as $offer){
            if(preg_match('/^(?:varios|various|soundtrack|ost)\b/i',Str::ascii($offer->album->artist)) || preg_match('/placeholder|no[-_]?image|no[-_]?cover|default[-_]?cover/i',$offer->image))continue;
            $key=$this->candidates->artistKey($offer->album->artist).'|'.$offer->format;$urlKey=hash('sha256',$offer->image);
            $groups[$key][$offer->album_id][$urlKey]??=$offer;
        }
        DB::transaction(function()use($run,$groups){
            $now=now();$pairs=[];$urlIds=[];
            $imageId=function(Offer $offer)use($run,&$urlIds){
                $key=hash('sha256',$offer->image);
                if(!isset($urlIds[$key]))$urlIds[$key]=CoverScanImage::firstOrCreate(['cover_scan_id'=>$run->id,'url_hash'=>$key],['url'=>$offer->image])->id;
                return $urlIds[$key];
            };
            foreach($groups as $albums){
                $albums=array_values(array_map('array_values',$albums));
                for($i=0;$i<count($albums);$i++)for($j=$i+1;$j<count($albums);$j++){
                    $a=$albums[$i][0];$b=$albums[$j][0];
                    if(!$this->candidates->compatible($a,$b) || $this->candidates->similarity($a->album->title,$b->album->title)<CoverCandidates::MIN_TITLE_SIMILARITY)continue;
                    foreach($albums[$i] as $one)foreach($albums[$j] as $two){
                        [$left,$right]=$one->id<$two->id?[$one,$two]:[$two,$one];
                        $pairs[]=['cover_scan_id'=>$run->id,'left_offer_id'=>$left->id,'right_offer_id'=>$right->id,'left_album_id'=>$left->album_id,'right_album_id'=>$right->album_id,'format'=>$left->format,
                            'left_image_id'=>$imageId($left),'right_image_id'=>$imageId($right),'status'=>'pending','created_at'=>$now,'updated_at'=>$now];
                        if(count($pairs)>=250){CoverScanPair::insertOrIgnore($pairs);$pairs=[];}
                    }
                }
            }
            if($pairs)CoverScanPair::insertOrIgnore($pairs);
            $run->update(['planned_at'=>now(),'total_pairs'=>CoverScanPair::where('cover_scan_id',$run->id)->count(),'total_images'=>CoverScanImage::where('cover_scan_id',$run->id)->count(),'heartbeat_at'=>now()]);
        });
    }
}
