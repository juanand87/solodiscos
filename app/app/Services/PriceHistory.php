<?php
namespace App\Services;
use App\Models\Offer;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB,Cache};

class PriceHistory {
    public const MAX_GAP_HOURS=72;
    public static function identity($offer): string {
        return hash('sha256',implode('|',[$offer->id,$offer->album_id,$offer->format,$offer->condition,$offer->currency,$offer->title]));
    }
    private function available(Offer $offer): bool {
        return !$offer->excluded_reason && !$offer->missing_since && $offer->album_id && in_array($offer->format,['cd','vinyl'])
            && $offer->availability==='in_stock' && is_numeric($offer->price) && $offer->price>0;
    }
    public function record(Offer $offer): void {
        DB::transaction(fn()=>$this->recordCurrent($offer));
    }
    public static function invalidate(): void {Cache::forever('store-rankings:revision',bin2hex(random_bytes(12)));}
    private function recordCurrent(Offer $offer): void {
        $at=$offer->checked_at??now();$stamp=$at->format('Y-m-d H:i:s');$identity=self::identity($offer);$available=$this->available($offer);
        $state=DB::table('offer_price_tracking')->where('offer_id',$offer->id)->lockForUpdate()->first();
        if(!$state && !$available)return;
        if($state && $state->last_checked_at>$stamp)return;
        if(!$state || $state->identity_key!==$identity || (float)$state->last_price!==(float)$offer->price || (bool)$state->last_available!==$available)
            DB::afterCommit(fn()=>self::invalidate());
        $values=['identity_key'=>$identity,'last_price'=>$offer->price,'last_checked_at'=>$stamp,'last_available'=>$available];
        if(!$state){$values+=['status'=>'none'];}
        else {
            $gap=Carbon::parse($state->last_checked_at)->diffInSeconds($at)>self::MAX_GAP_HOURS*3600;
            $same=$state->identity_key===$identity;
            $rise=$available && $same && $state->last_price!==null && (float)$offer->price>(float)$state->last_price;
            if(!$available || !$same || $gap || $rise){
                $values+=['status'=>'closed','closed_at'=>$stamp,'close_reason'=>!$available?'unavailable':(!$same?'identity_changed':($gap?'tracking_gap':'price_increased'))];
            }elseif($state->last_available && $state->last_price!==null && (float)$offer->price<(float)$state->last_price){
                if(!in_array($state->status,['offer','drop'])){
                    $reference=$this->reference($offer->id,$identity,$at);
                    $verified=$reference!==null && (float)$offer->price<$reference;
                    $values+=['status'=>$verified?'offer':'drop','reference_price'=>$verified?$reference:$state->last_price,
                        'reference_from'=>$verified?$at->copy()->subDays(30)->format('Y-m-d H:i:s'):$state->last_checked_at,
                        'reference_to'=>$stamp,'started_at'=>$stamp,'closed_at'=>null,'close_reason'=>null];
                }
            }
        }
        DB::table('offer_price_tracking')->updateOrInsert(['offer_id'=>$offer->id],$values);
        $day=DB::table('offer_price_days')->where('offer_id',$offer->id)->where('day',$at->toDateString())->lockForUpdate()->first();
        $valid=$available && (!$day || ($day->valid && $day->identity_key===$identity));
        $minimum=$available?$offer->price:null;
        if($day && $day->minimum_price!==null && $day->identity_key===$identity && $minimum!==null)$minimum=min((float)$day->minimum_price,(float)$minimum);
        DB::table('offer_price_days')->updateOrInsert(['offer_id'=>$offer->id,'day'=>$at->toDateString()],
            ['identity_key'=>$identity,'minimum_price'=>$minimum,'last_price'=>$offer->price,'valid'=>$valid,
                'first_checked_at'=>$day?->first_checked_at??$stamp,'last_checked_at'=>$stamp]);
    }
    private function reference(int $offer,string $identity,Carbon $at): ?float {
        $start=$at->copy()->subDays(30);$earliest=$start->copy()->subHours(self::MAX_GAP_HOURS);
        $days=DB::table('offer_price_days')->where('offer_id',$offer)->where('last_checked_at','>=',$earliest)
            ->where('first_checked_at','<',$at)->orderBy('day')->get();
        $anchor=$days->filter(fn($d)=>$d->valid && $d->identity_key===$identity && $d->last_checked_at<=$start->format('Y-m-d H:i:s'))->last();
        if(!$anchor)return null;
        $last=Carbon::parse($anchor->last_checked_at);$minimum=(float)$anchor->last_price;$count=1;
        foreach($days as $day){
            if($day->day<=$anchor->day)continue;
            if(!$day->valid || $day->identity_key!==$identity || $day->minimum_price===null)return null;
            $first=Carbon::parse($day->first_checked_at);
            if($last->diffInSeconds($first)>self::MAX_GAP_HOURS*3600)return null;
            $last=Carbon::parse($day->last_checked_at);$minimum=min($minimum,(float)$day->minimum_price);$count++;
        }
        return $count>=10 && $last->diffInSeconds($at)<=self::MAX_GAP_HOURS*3600 && $minimum>0?$minimum:null;
    }
    public function seed(): int {
        $count=0;
        Offer::available()->where('checked_at','>=',now()->subHours(self::MAX_GAP_HOURS))->chunkById(200,function($offers)use(&$count){
            $states=[];$days=[];
            foreach($offers as $offer){
                $at=$offer->checked_at->format('Y-m-d H:i:s');$identity=self::identity($offer);
                $states[]=['offer_id'=>$offer->id,'identity_key'=>$identity,'last_price'=>$offer->price,'last_checked_at'=>$at,'last_available'=>true,'status'=>'none'];
                $days[]=['offer_id'=>$offer->id,'day'=>$offer->checked_at->toDateString(),'identity_key'=>$identity,'minimum_price'=>$offer->price,
                    'last_price'=>$offer->price,'valid'=>true,'first_checked_at'=>$at,'last_checked_at'=>$at];
            }
            DB::transaction(function()use($states,$days){
                DB::table('offer_price_tracking')->insertOrIgnore($states);DB::table('offer_price_days')->insertOrIgnore($days);
            });$count+=count($offers);
        });return $count;
    }
    public function badge(Offer $offer): ?array {
        if(!$offer->checked_at || $offer->checked_at->lt(now()->subHours(self::MAX_GAP_HOURS)) || !$this->available($offer))return null;
        $state=DB::table('offer_price_tracking')->where('offer_id',$offer->id)->whereIn('status',['offer','drop'])->first();
        if(!$state || $state->identity_key!==self::identity($offer) || (float)$state->last_price!==(float)$offer->price
            || $state->last_checked_at!==$offer->checked_at->format('Y-m-d H:i:s') || $state->reference_price<=$offer->price)return null;
        return ['verified'=>$state->status==='offer','reference'=>(float)$state->reference_price,'saving'=>(float)$state->reference_price-(float)$offer->price,
            'percent'=>100*(1-(float)$offer->price/(float)$state->reference_price),'started_at'=>$state->started_at,'reference_from'=>$state->reference_from];
    }
}
