<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CoverMatch extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['changes'=>'array','reviewed_at'=>'datetime']; }
    public static function retireStalePending(): int {
        return static::where('status','pending')->where(function($query){
            foreach(['left','right'] as $side){
                $query->orWhereDoesntHave($side.'Offer',function($offer)use($side){
                    $offer->whereColumn('offers.album_id','cover_matches.'.$side.'_album_id')
                        ->whereColumn('offers.format','cover_matches.format')->whereNull('offers.excluded_reason')
                        ->whereExists(function($cover)use($side){
                            $cover->selectRaw('1')->from('cover_fingerprints')
                                ->whereColumn('cover_fingerprints.id','cover_matches.'.$side.'_cover_id')
                                ->whereColumn('cover_fingerprints.url','offers.image')->where('cover_fingerprints.status','ready');
                        });
                });
            }
        })->update(['status'=>'obsolete']);
    }
    public function leftOffer() { return $this->belongsTo(Offer::class,'left_offer_id'); }
    public function rightOffer() { return $this->belongsTo(Offer::class,'right_offer_id'); }
    public function leftCover() { return $this->belongsTo(CoverFingerprint::class,'left_cover_id'); }
    public function rightCover() { return $this->belongsTo(CoverFingerprint::class,'right_cover_id'); }
}
