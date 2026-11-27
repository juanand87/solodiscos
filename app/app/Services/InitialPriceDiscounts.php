<?php
namespace App\Services;
use App\Models\Offer;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InitialPriceDiscounts {
    public function query(){
        return DB::table('offers as o')->join('stores as s','s.id','=','o.store_id')->join('albums as a','a.id','=','o.album_id')
            ->join('offer_observations as initial',function($j){$j->on('initial.id','=',DB::raw("(SELECT p.id FROM offer_observations p WHERE p.offer_id=o.id AND p.currency=o.currency AND p.price>0 AND p.created_at<=o.checked_at ORDER BY p.created_at,p.id LIMIT 1)"));})
            ->where('s.active',true)->whereNull('o.excluded_reason')->whereNull('o.missing_since')->where('o.availability','in_stock')->where('o.currency','CLP')->where('o.price','>',0)
            ->whereIn('o.format',['cd','vinyl'])->where('o.checked_at','>=',now()->subHours(72))
            ->select('o.id','o.album_id','o.store_id','o.title','o.image','o.price','o.currency','o.format','o.condition','o.checked_at','s.name as store_name','s.logo_url','a.artist','a.title as album_title',
                'initial.price as initial_price','initial.created_at as initial_at')->selectRaw('100.0*(initial.price-o.price)/initial.price as drop_percent');
    }
    public function attach($offers): void {
        if($offers->isEmpty())return;
        $initials=$this->query()->whereIn('o.id',$offers->pluck('id')->unique()->all())->get()->keyBy('id');
        foreach($offers as $offer){
            $initial=$initials->get($offer->id);
            $offer->setRelation('initialDiscount',$initial && $initial->drop_percent>0?collect(['percent'=>(float)$initial->drop_percent,'initial'=>(float)$initial->initial_price,'at'=>$initial->initial_at]):null);
        }
    }
    public function observations(int $id){return DB::table('offer_observations')->where('offer_id',$id)->where('currency','CLP')->where('price','>',0)->orderBy('created_at')->orderBy('id');}
    public function charts($offers): array {
        $ids=collect($offers)->pluck('id')->all();if(!$ids)return [];
        $byId=collect($offers)->map(fn($offer)=>(object)['id'=>$offer->id,'price'=>(float)$offer->price,'checked_at'=>Carbon::parse($offer->checked_at)->format('Y-m-d H:i:s')])->keyBy('id');$series=[];
        foreach(DB::table('offer_observations')->whereIn('offer_id',$ids)->where('currency','CLP')->where('price','>',0)->orderBy('offer_id')->orderBy('created_at')->orderBy('id')->cursor() as $row){
            if($row->created_at>$byId[$row->offer_id]->checked_at)continue;
            $points=&$series[$row->offer_id];$points??=[];
            if(!$points || end($points)['price']!==(float)$row->price)$points[]=['at'=>$row->created_at,'price'=>(float)$row->price];
            if(count($points)>400)$points=$this->reduce($points,200);
            unset($points);
        }
        foreach($byId as $id=>$offer){
            $series[$id]??=[];$last=end($series[$id]);
            if(!$last || $last['at']<$offer->checked_at)$series[$id][]=['at'=>$offer->checked_at,'price'=>(float)$offer->price];
            $series[$id]=$this->coordinates($this->reduce($series[$id],200));
        }
        return $series;
    }
    private function reduce(array $points,int $limit): array {
        if(count($points)<=$limit)return $points;
        $kept=[0=>$points[0],count($points)-1=>$points[array_key_last($points)]];$size=(int)ceil(count($points)/(($limit-2)/2));
        for($start=1;$start<count($points)-1;$start+=$size){$indices=range($start,min(count($points)-2,$start+$size-1));$min=$max=$indices[0];foreach($indices as $i){if($points[$i]['price']<$points[$min]['price'])$min=$i;if($points[$i]['price']>$points[$max]['price'])$max=$i;}$kept[$min]=$points[$min];$kept[$max]=$points[$max];}
        ksort($kept);return array_values($kept);
    }
    private function coordinates(array $points): array {
        $prices=array_column($points,'price');$low=min($prices);$high=max($prices);$start=Carbon::parse($points[0]['at'])->timestamp;$end=Carbon::parse($points[array_key_last($points)]['at'])->timestamp;
        foreach($points as &$point){$point['x']=round(35+530*(Carbon::parse($point['at'])->timestamp-$start)/max(1,$end-$start),2);$point['y']=$high===$low?75:round(115-85*($point['price']-$low)/($high-$low),2);}unset($point);
        return ['points'=>$points,'line'=>implode(' ',array_map(fn($p)=>$p['x'].','.$p['y'],$points)),'low'=>$low,'high'=>$high,'first'=>$points[0]['at'],'last'=>$points[array_key_last($points)]['at']];
    }
}
