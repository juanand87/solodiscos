<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CoverScanPair extends Model {
    protected $guarded=[];
    public function leftImage(){return $this->belongsTo(CoverScanImage::class,'left_image_id');}
    public function rightImage(){return $this->belongsTo(CoverScanImage::class,'right_image_id');}
    public function leftOffer(){return $this->belongsTo(Offer::class,'left_offer_id');}
    public function rightOffer(){return $this->belongsTo(Offer::class,'right_offer_id');}
}
