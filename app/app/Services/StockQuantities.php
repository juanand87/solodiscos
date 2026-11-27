<?php
namespace App\Services;
use App\Models\{Offer,Store};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockQuantities {
    public function integer(mixed $value): ?int {
        if(!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>2147483647 || floor((float)$value)!==(float)$value)return null;
        return (int)$value;
    }
    public function wooReported(array $product): ?int {
        if(($product['manage_stock']??null)===false)return null;
        foreach(['stock_quantity','low_stock_remaining'] as $field)if(($q=$this->integer($product[$field]??null))!==null)return $q;
        $text=html_entity_decode(strip_tags($product['stock_availability']['text']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if(preg_match('/\b(\d+)\s+(?:disponibles?|existencias?|unidades?|en\s+stock|in\s+stock|available)\b/iu',$text,$m))return $this->integer($m[1]);
        return null;
    }
    public function reading(string $availability,array $source): array {
        $quantity=$this->integer($source['reported_stock_quantity']??null);
        if($quantity===null && (int)($source['stock_control']??0)===1)$quantity=$this->integer($source['total_stock']??null);
        if($quantity!==null && !($availability==='in_stock' && $quantity===0) && !($availability==='out_of_stock' && $quantity>0))return ['stock_quantity'=>$quantity,'stock_quantity_source'=>'reported'];
        return ['stock_quantity'=>match($availability){'in_stock'=>1,'out_of_stock','preorder','backorder'=>0,default=>null},'stock_quantity_source'=>match($availability){'unknown'=>'unknown','out_of_stock'=>'availability_zero',default=>'estimated'}];
    }
    public function previous(Offer $offer): array {
        $reading=$offer->stock_quantity!==null?['stock_quantity'=>$offer->stock_quantity,'stock_quantity_source'=>$offer->stock_quantity_source]:$this->reading($offer->availability,[]);
        if($offer->missing_since && $offer->stock_quantity===null)$reading=['stock_quantity'=>0,'stock_quantity_source'=>'missing'];
        return $reading+['price'=>$offer->price,'currency'=>$offer->currency,'availability'=>$offer->availability,'title'=>$offer->title,
            'created_at'=>$offer->stock_quantity_checked_at?->format('Y-m-d H:i:s')??$offer->checked_at?->format('Y-m-d H:i:s')];
    }
    public function decrease(Offer $offer,array $previous,array $current): ?array {
        $before=$previous['stock_quantity']??null;$after=$current['stock_quantity']??null;
        if($before===null || $after===null || $before<=0 || $after>=$before)return null;
        if(isset($previous['title']) && Str::slug($previous['title'])!==Str::slug($offer->title))return null;
        if(($current['stock_quantity_source']??'')==='missing')return ['units'=>$before-$after,'stock_evidence'=>'catalog_disappearance'];
        if(($previous['stock_quantity_source']??'')==='reported' && ($current['stock_quantity_source']??'')==='reported')return ['units'=>$before-$after,'stock_evidence'=>'reported_quantity'];
        if(($previous['stock_quantity_source']??'')==='reported' && ($current['stock_quantity_source']??'')==='availability_zero')return ['units'=>$before,'stock_evidence'=>'reported_to_zero'];
        if(($previous['availability']??'')==='in_stock' && $current['availability']==='out_of_stock' && $after===0)return ['units'=>1,'stock_evidence'=>'estimated_availability'];
        return null;
    }
    public function missingSales(Store $store,$started): int {
        $count=0;
        $store->offers()->where('missing_since',$started)->whereNull('excluded_reason')->whereIn('format',['cd','vinyl'])->where('stock_quantity','>',0)->orderBy('id')->chunkById(100,function($offers)use($store,$started,&$count){
            foreach($offers as $candidate)DB::transaction(function()use($store,$candidate,$started,&$count){
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();$offer=Offer::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if(!$offer->missing_since || $offer->checked_at?->gte($started) || $offer->stock_quantity<=0)return;
                $source=$offer->source_metadata??[];
                if(($source['missing_stock_result']??null)==='in_stock' && ($source['missing_stock_checked_at']??'')>=\Carbon\Carbon::parse($started)->utc()->format('Y-m-d H:i:s'))return;
                $previous=$this->previous($offer); // Preserve its last positive quantity before marking this disappearance.
                $previous['stock_quantity']=$offer->stock_quantity;$previous['stock_quantity_source']=$offer->stock_quantity_source;
                $current=['price'=>$offer->price,'currency'=>$offer->currency,'availability'=>$offer->availability,'stock_quantity'=>0,'stock_quantity_source'=>'missing','created_at'=>now(),'previous_checked_at'=>$previous['created_at']];
                $observation=DB::table('offer_observations')->insertGetId(['offer_id'=>$offer->id]+$current);
                app(OfferEvents::class)->record($offer,$previous,$current,$observation,false);
                $offer->update(['stock_quantity'=>0,'stock_quantity_source'=>'missing','stock_quantity_checked_at'=>now()]);$count++;
            });
        });
        return $count;
    }
}
