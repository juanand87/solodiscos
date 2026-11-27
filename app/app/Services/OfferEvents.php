<?php
namespace App\Services;
use App\Models\Offer;
use Illuminate\Support\Facades\DB;

class OfferEvents {
    public const TYPES=['added'=>'Recién agregados','out_of_stock'=>'Agotados','restocked'=>'Reposiciones','price_changed'=>'Cambios de precio','possible_sale'=>'Posibles copias vendidas'];
    public function record(Offer $offer,?array $previous,array $current,?int $observation,bool $initial,bool $historical=false): void {
        $base=['offer_id'=>$offer->id,'store_id'=>$offer->store_id,'occurred_at'=>$current['created_at'],
            'previous_checked_at'=>$current['previous_checked_at']??($previous['created_at']??null),
            'previous_price'=>$previous['price']??null,'price'=>$current['price'],'currency'=>$current['currency']??null,
            'previous_availability'=>$previous['availability']??null,'availability'=>$current['availability'],'historical'=>$historical,'initial_load'=>false,
            'stock_before'=>$previous['stock_quantity']??null,'stock_after'=>$current['stock_quantity']??null,'units'=>null,'stock_evidence'=>null];
        $rows=[];
        if(!$previous)$rows[]=$base+['event_key'=>'added:'.$offer->id,'type'=>'added'];
        if(!$previous){$rows[0]['initial_load']=$initial || $current['availability']!=='in_stock';$rows[0]['occurred_at']=$offer->created_at->format('Y-m-d H:i:s');}
        if($previous && $observation){
            $from=$previous['availability'];$to=$current['availability'];$types=[];
            if($from==='in_stock' && $to==='out_of_stock')$types[]='out_of_stock';
            if($from==='out_of_stock' && $to==='in_stock')$types[]='restocked';
            // Older observations have no currency: expose them as numerical changes with currency unspecified.
            $sameCurrency=($previous['currency']??null)===($current['currency']??null);
            if($sameCurrency && is_numeric($previous['price']) && is_numeric($current['price'])
                && number_format((float)$previous['price'],2,'.','')!==number_format((float)$current['price'],2,'.',''))$types[]='price_changed';
            foreach($types as $type)$rows[]=$base+['event_key'=>'observation:'.$observation.':'.$type,'type'=>$type];
            if(!$historical && ($sale=app(StockQuantities::class)->decrease($offer,$previous,$current)))$rows[]=array_merge($base,['event_key'=>'observation:'.$observation.':possible_sale','type'=>'possible_sale',
                'units'=>$sale['units'],'stock_evidence'=>$sale['stock_evidence']]);
        }
        if($rows)DB::table('offer_events')->insertOrIgnore($rows);
    }
    public function backfill(): int {
        $cutoffs=DB::table('scrape_runs')->whereIn('status',['success','warning'])->whereNotNull('finished_at')
            ->selectRaw('store_id, MIN(finished_at) AS cutoff')->groupBy('store_id')->pluck('cutoff','store_id');
        $count=0;
        Offer::select('id','store_id','created_at','price','currency','availability')->chunkById(200,function($offers)use($cutoffs,&$count){
            $observations=DB::table('offer_observations')->whereIn('offer_id',$offers->pluck('id'))->orderBy('id')->get()->groupBy('offer_id');
            DB::transaction(function()use($offers,$cutoffs,$observations,&$count){foreach($offers as $offer){
                $initial=!isset($cutoffs[$offer->store_id]) || $offer->created_at->format('Y-m-d H:i:s')<=$cutoffs[$offer->store_id];
                    $previous=null;$rows=$observations->get($offer->id,collect());
                    foreach($rows as $row){
                        $current=(array)$row;
                        $this->record($offer,$previous,$current,$row->id,$initial,true);$previous=$current;
                    }
                    if($rows->isEmpty())$this->record($offer,null,['price'=>$offer->price,'availability'=>$offer->availability,
                        'currency'=>null,'created_at'=>$offer->created_at->format('Y-m-d H:i:s')],null,$initial,true);
                $count++;
            }});
        });
        return $count;
    }
}
