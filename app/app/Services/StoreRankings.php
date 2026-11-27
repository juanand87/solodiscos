<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Cache};

class StoreRankings {
    public const MIN_COMPARABLE=100;
    public const MIN_STORES=2;
    public const MIN_DISCOUNTS=10;
    public static function median(array $values): float {
        sort($values,SORT_NUMERIC);$n=count($values);if(!$n)return 0;
        return $n%2?(float)$values[intdiv($n,2)]:($values[$n/2-1]+$values[$n/2])/2;
    }
    public function get(array $filters): array {
        ksort($filters);
        return Cache::remember('store-rankings:v7:'.Cache::get('store-rankings:revision','0').':'.hash('sha256',json_encode($filters)),300,fn()=>$this->calculate($filters));
    }
    private function eligibleQuery(array $filters): \Illuminate\Database\Query\Builder {
        $query=DB::table('offers as o')->join('stores as s','s.id','=','o.store_id')->join('albums as a','a.id','=','o.album_id')
            ->where('s.active',true)->whereNull('o.excluded_reason')
            ->where('o.availability','in_stock')->whereNull('o.missing_since')->whereIn('o.format',['cd','vinyl'])->where('o.price','>',0)
            ->where('o.checked_at','>=',now()->subHours(PriceHistory::MAX_GAP_HOURS))->where('o.currency',$filters['currency']??'CLP');
        if(!empty($filters['format']))$query->where('o.format',$filters['format']);
        if(!empty($filters['condition']))$query->where('o.condition',$filters['condition']);
        return $query;
    }
    public function comparisonSample(int $store,array $filters,int $page=1,int $perPage=12): \Illuminate\Pagination\LengthAwarePaginator {
        $query=$this->eligibleQuery($filters);
        $albums=$this->eligibleQuery($filters)->where('o.store_id',$store)->distinct()->pluck('o.album_id')->all();
        $offers=$query->whereIn('o.album_id',$albums)->select('o.id','o.album_id','o.store_id','o.format','o.condition','o.currency','o.price','o.title','o.image','o.url','o.checked_at',
            's.name as store_name','s.logo_url','a.artist','a.title as album_title')->orderBy('o.id')->get();
        $groups=[];
        foreach($offers as $offer){
            $key=$offer->album_id.'|'.$offer->format.'|'.$offer->condition;
            if(!isset($groups[$key][$offer->store_id]) || (float)$offer->price<(float)$groups[$key][$offer->store_id]->price)$groups[$key][$offer->store_id]=$offer;
        }
        $items=[];
        foreach($groups as $group){
            if(!isset($group[$store]) || count($group)<self::MIN_STORES)continue;
            $anchor=$group[$store];if(!in_array($anchor->condition,['new','used']))continue;
            if(!empty($filters['cheapest']) && (float)$anchor->price>min(array_map(fn($offer)=>(float)$offer->price,$group)))continue;
            usort($group,fn($a,$b)=>(float)$a->price<=>(float)$b->price ?: $a->store_id<=>$b->store_id);
            $last=null;$rank=0;
            foreach($group as $offer){if($last===null || (float)$offer->price!==$last){$rank++;$last=(float)$offer->price;}$offer->price_rank=$rank;}
            $items[]=['anchor'=>$anchor,'offers'=>array_slice($group,0,3),'store_count'=>count($group)];
        }
        usort($items,fn($a,$b)=>strnatcasecmp($a['anchor']->artist,$b['anchor']->artist) ?: strnatcasecmp($a['anchor']->album_title,$b['anchor']->album_title) ?: $a['anchor']->album_id<=>$b['anchor']->album_id ?: strcmp($a['anchor']->format,$b['anchor']->format) ?: strcmp($a['anchor']->condition,$b['anchor']->condition));
        $perPage=max(1,min(10000,$perPage));
        return new \Illuminate\Pagination\LengthAwarePaginator(array_slice($items,($page-1)*$perPage,$perPage),count($items),$perPage,$page,
            ['path'=>\Illuminate\Pagination\Paginator::resolveCurrentPath(),'query'=>request()->query()]);
    }
    public function calculate(array $filters): array {
        $stores=DB::table('stores')->where('active',true)->orderBy('name')->get(['id','name','logo_url','url'])->keyBy('id');
        $ids=$this->eligibleQuery($filters)->orderBy('o.id')->pluck('o.id')->all();
        $query=$this->eligibleQuery($filters)->leftJoin('offer_price_tracking as t','t.offer_id','=','o.id');
        $query->select('o.id','o.store_id','o.album_id','o.title','o.format','o.condition','o.currency','o.price','o.checked_at','o.url','o.image',
            'a.artist','a.title as album_title','t.identity_key as tracking_identity','t.status as deal_status','t.reference_price','t.started_at as deal_started_at',
            't.last_price as tracked_price','t.last_checked_at as tracked_at','t.reference_from');
        // Resolve only lightweight IDs first, then fetch details through primary-key batches.
        $offers=(function()use($query,$ids){
            foreach(array_chunk($ids,1000) as $batch)
                foreach((clone $query)->whereIn('o.id',$batch)->orderBy('o.id')->get() as $offer)yield $offer;
        })();
        $variety=[];$groups=[];$feed=[];$offerCount=0;$dealCount=0;
        foreach($stores as $store)$variety[$store->id]=['store'=>$store,'albums'=>[],'cd'=>[],'vinyl'=>[],'publications'=>0,'checked_at'=>null];
        foreach($offers as $offer){
            $offerCount++;
            $offer->store_name=$stores[$offer->store_id]->name;
            $v=&$variety[$offer->store_id];$v['albums'][$offer->album_id.'|'.$offer->format]=true;$v[$offer->format][$offer->album_id]=true;$v['publications']++;
            $v['checked_at']=max($v['checked_at']??'',$offer->checked_at);unset($v);
            $key=$offer->album_id.'|'.$offer->format.'|'.$offer->condition;
            $offer->deal=null;
            if(in_array($offer->deal_status,['offer','drop']) && $offer->tracking_identity===PriceHistory::identity($offer)
                && $offer->tracked_at===$offer->checked_at && (float)$offer->tracked_price===(float)$offer->price && $offer->reference_price>$offer->price){
                $offer->deal=['verified'=>$offer->deal_status==='offer','reference'=>(float)$offer->reference_price,
                    'saving'=>(float)$offer->reference_price-(float)$offer->price,'percent'=>100*(1-(float)$offer->price/(float)$offer->reference_price)];
                $feed[]=$offer;
                $dealCount++;
                // Keep only the newest feed entries; total deal count remains exact.
                if(count($feed)>48){
                    usort($feed,fn($a,$b)=>strcmp($b->deal_started_at,$a->deal_started_at)?:($b->id<=>$a->id));
                    $feed=array_slice($feed,0,24);
                }
            }
            // Full titles, URLs and images are needed only for the short deal feed.
            // Comparison groups keep the cheapest price and the fields used below.
            if(!isset($groups[$key][$offer->store_id]) || (float)$offer->price<(float)$groups[$key][$offer->store_id]->price)
                $groups[$key][$offer->store_id]=(object)['album_id'=>$offer->album_id,'format'=>$offer->format,'condition'=>$offer->condition,
                    'price'=>$offer->price,'checked_at'=>$offer->checked_at,'deal'=>$offer->deal];
        }
        $cheap=['new'=>[],'used'=>[]];$discounts=[];
        foreach($stores as $store){
            foreach(['new','used'] as $condition)$cheap[$condition][$store->id]=['store'=>$store,'ratios'=>[],'wins'=>0,'checked_at'=>null,'peers'=>[],'solo_peers'=>[]];
            $discounts[$store->id]=['store'=>$store,'albums'=>[],'checked_at'=>null];
        }
        foreach($groups as $items){
            $first=reset($items);$condition=$first->condition;
            if(count($items)>=self::MIN_STORES && in_array($condition,['new','used'])){
                $lowest=min(array_map(fn($o)=>(float)$o->price,$items));
                foreach($items as $storeId=>$offer){
                    $others=[];foreach($items as $otherId=>$other)if($otherId!==$storeId)$others[]=(float)$other->price;
                    $row=&$cheap[$condition][$storeId];$row['ratios'][]=(float)$offer->price/self::median($others);
                    foreach($items as $peerId=>$peer)if($peerId!==$storeId){
                        $row['peers'][$peerId]=true;
                        if(count($others)===1)$row['solo_peers'][$peerId]=($row['solo_peers'][$peerId]??0)+1;
                    }
                    if((float)$offer->price===$lowest)$row['wins']++;$row['checked_at']=max($row['checked_at']??'',$offer->checked_at);unset($row);
                }
            }
            foreach($items as $storeId=>$offer){
                if(!$offer->deal || !$offer->deal['verified'])continue;
                $key=$offer->album_id.'|'.$offer->format;$row=&$discounts[$storeId];
                $row['albums'][$key]=max($row['albums'][$key]??0,$offer->deal['percent']);
                $row['checked_at']=max($row['checked_at']??'',$offer->checked_at);unset($row);
            }
        }
        $recommendations=[];
        foreach($stores as $store){
            $ratios=array_merge($cheap['new'][$store->id]['ratios'],$cheap['used'][$store->id]['ratios']);$sample=count($ratios);
            $index=$sample?100*self::median($ratios):null;
            $recommendations[]=['store'=>$store,'sample'=>$sample,'eligible'=>$sample>=self::MIN_COMPARABLE,'index'=>$index,
                'cheaper_products'=>$cheap['new'][$store->id]['wins']+$cheap['used'][$store->id]['wins'],
                'stars'=>null];
        }
        usort($recommendations,fn($a,$b)=>($b['eligible']<=>$a['eligible']) ?: ($a['eligible']?($a['index']<=>$b['index']):0) ?: ($b['sample']<=>$a['sample']) ?: ($a['store']->id<=>$b['store']->id));
        foreach($recommendations as $position=>&$recommendation){
            if($recommendation['eligible'] && $position<10)$recommendation['stars']=round(5-$position*2/9,1);
        }unset($recommendation);
        foreach($cheap as $condition=>&$rows){
            foreach($rows as &$row){$row['sample']=count($row['ratios']);$row['eligible']=$row['sample']>=self::MIN_COMPARABLE;
                $row['index']=$row['sample']?100*self::median($row['ratios']):null;$row['wins_percent']=$row['sample']?100*$row['wins']/$row['sample']:0;
                $row['competitor_count']=count($row['peers']);ksort($row['solo_peers'],SORT_NUMERIC);
                $row['solo_comparisons']=$row['solo_peers']?max($row['solo_peers']):0;
                $row['solo_share']=$row['sample']?100*$row['solo_comparisons']/$row['sample']:0;
                $row['concentrated']=$row['solo_share']>50;
                $peerId=$row['solo_comparisons']?array_search($row['solo_comparisons'],$row['solo_peers'],true):null;
                $row['dominant_competitor']=$peerId!==null?$stores[$peerId]:null;
                unset($row['ratios'],$row['peers'],$row['solo_peers']);}unset($row);
            usort($rows,fn($a,$b)=>($b['eligible']<=>$a['eligible'])?:($a['eligible']?($a['index']<=>$b['index']):($b['sample']<=>$a['sample']))?:($b['sample']<=>$a['sample'])?:($a['store']->id<=>$b['store']->id));
        }unset($rows);
        foreach($variety as &$row){$row['total']=count($row['albums']);$row['cd']=count($row['cd']);$row['vinyl']=count($row['vinyl']);unset($row['albums']);}unset($row);
        foreach($discounts as $id=>&$row){$row['sample']=count($row['albums']);$row['eligible']=$row['sample']>=self::MIN_DISCOUNTS;
            $row['percent']=$row['sample']?self::median(array_values($row['albums'])):null;
            $row['catalog_total']=$variety[$id]['total'];$row['catalog_percent']=$row['catalog_total']?100*$row['sample']/$row['catalog_total']:0;unset($row['albums']);}unset($row);
        usort($variety,fn($a,$b)=>($b['total']<=>$a['total'])?:($a['store']->id<=>$b['store']->id));
        usort($discounts,fn($a,$b)=>($b['eligible']<=>$a['eligible'])?:($b['percent']<=>$a['percent'])?:($b['sample']<=>$a['sample'])?:($a['store']->id<=>$b['store']->id));
        usort($feed,fn($a,$b)=>strcmp($b->deal_started_at,$a->deal_started_at)?:($b->id<=>$a->id));
        return ['generated_at'=>now()->format('Y-m-d H:i:s'),'recommendations'=>$recommendations,'cheap'=>$cheap,'variety'=>$variety,'discounts'=>$discounts,'feed'=>array_slice($feed,0,24),
            'offer_count'=>$offerCount,'store_count'=>$stores->count(),'deal_count'=>$dealCount];
    }
}
