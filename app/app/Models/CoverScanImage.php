<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CoverScanImage extends Model {
    protected $guarded=[];
    protected function casts(): array {return ['downloaded'=>'boolean'];}
    public function fingerprint(){return $this->belongsTo(CoverFingerprint::class,'cover_fingerprint_id');}
}
