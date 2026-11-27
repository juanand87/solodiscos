<?php
namespace App\Services;
use App\Models\{Store,Offer};
use Illuminate\Support\Facades\DB;

class MissingStockReview {
    public function __construct(private StockAvailabilityVerifier $verifier,private OfferEvents $events) {}
    public function review(Store $store,int $limit=20,?callable $progress=null): array {
        $cutoff=now()->utc()->subDay()->format('Y-m-d H:i:s');$r=['checked'=>0,'confirmed'=>0,'changed'=>0,'unconfirmed'=>0];
        $offers=$store->offers()->whereNotNull('missing_since')->whereNull('excluded_reason')->whereIn('format',['cd','vinyl'])
            ->whereIn('availability',['in_stock','unknown'])->where(fn($q)=>$q->whereNull('source_metadata->missing_stock_checked_at')->orWhere('source_metadata->missing_stock_checked_at','<=',$cutoff))
            ->orderBy('id')->limit($limit)->get();
        foreach($offers as $before){
            try{$stock=$this->verifier->verify($before);}catch(\Throwable){$stock=null;}
            $r['checked']++;$r[$stock?'confirmed':'unconfirmed']++;
            DB::transaction(function()use($store,$before,$stock,&$r){
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();$offer=Offer::whereKey($before->id)->lockForUpdate()->firstOrFail();
                if(!$offer->missing_since || $offer->getRawOriginal('checked_at')!==$before->getRawOriginal('checked_at') || $offer->url!==$before->url)return;
                $source=$offer->source_metadata??[];$source['missing_stock_checked_at']=now()->utc()->format('Y-m-d H:i:s');$source['missing_stock_result']=$stock??'unconfirmed';
                $previous=app(StockQuantities::class)->previous($offer);
                if($stock && $stock!==$offer->availability){
                    $offer->availability=$stock;$r['changed']++;
                    $quantity=app(StockQuantities::class)->reading($stock,[]);
                    $current=['price'=>$offer->price,'availability'=>$stock,'currency'=>$offer->currency,'created_at'=>now(),'previous_checked_at'=>$previous['created_at']]+$quantity;
                    $observation=DB::table('offer_observations')->insertGetId(['offer_id'=>$offer->id]+$current);
                    $this->events->record($offer,$previous,$current,$observation,false);
                    $offer->fill($quantity+['stock_quantity_checked_at'=>now()]);
                    DB::table('offer_price_tracking')->where('offer_id',$offer->id)->update(['status'=>'closed','closed_at'=>now(),'close_reason'=>'missing_stock_verified']);
                }
                // Absence remains distinct from availability; never refresh price/checked_at or republish from a stock-only check.
                $offer->source_metadata=$source;$offer->save();
            });
            if($progress)$progress($r['checked']);
            usleep(250000);
        }
        if($r['changed'])PriceHistory::invalidate();return $r;
    }
}
