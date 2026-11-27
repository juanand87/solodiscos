<?php
namespace App\Services;
use App\Models\{Store,Offer};
use Illuminate\Support\Facades\DB;

class StoreConditions {
    public function configure(Store $store,array $values,bool $reviewAutomatic=false): int {
        return DB::transaction(function()use($store,$values,$reviewAutomatic){
            if($store->exists)$store=Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $before=$store->default_condition;$store->fill($values);
            $rulesChanged=$store->isDirty(['new_condition_categories','used_condition_categories','category_condition_fallback']);
            $store->save();$mode=$store->default_condition;$changed=[];
            if(in_array($mode,['new','used'])){
                $changed=$store->offers()->where('condition','!=',$mode)->lockForUpdate()->pluck('id')->all();
                foreach(array_chunk($changed,500) as $ids)Offer::whereKey($ids)->update(['condition'=>$mode]);
            }elseif($before!==$mode || $reviewAutomatic || ($mode==='category' && $rulesChanged)){
                $normalizer=app(ProductNormalizer::class);
                $store->offers()->where('reviewed',false)->orderBy('id')->lockForUpdate()->chunkById(200,function($offers)use($normalizer,$store,$mode,$before,$reviewAutomatic,&$changed){
                    foreach($offers as $offer){
                        $source=$offer->source_metadata??[];$metadata=[];
                        foreach(['product_type','tags','categories','attributes'] as $field){
                            $value=$source[$field]??[];
                            if(is_string($value))$metadata[]=$value;
                            elseif(is_array($value))array_walk_recursive($value,function($text)use(&$metadata){if(is_string($text))$metadata[]=$text;});
                        }
                        $parsed=$normalizer->parse($offer->title,implode(' ',$metadata),'unknown',$source,$offer->description??'');
                        $condition=$mode==='category'?app(CategoryConditions::class)->identify($store,$source):$parsed['condition'];
                        // Legacy imports did not retain categories: lack of metadata must not erase an existing classification.
                        if($mode==='unknown' && $before==='unknown' && $reviewAutomatic && !$metadata && !$parsed['condition_evidence'] && $condition==='unknown' && $offer->condition!=='unknown')continue;
                        if($offer->condition!==$condition){$offer->update(['condition'=>$condition]);$changed[]=$offer->id;}
                    }
                });
            }
            foreach(array_chunk($changed,500) as $ids)DB::table('offer_price_tracking')->whereIn('offer_id',$ids)
                ->update(['status'=>'closed','closed_at'=>now(),'close_reason'=>'condition_policy_changed']);
            DB::afterCommit(fn()=>PriceHistory::invalidate());
            return count($changed);
        });
    }
}
