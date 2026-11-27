<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StoreCorrection extends Model {
    public const FIELDS=['identity'=>'Artista o álbum','format'=>'Formato','condition'=>'Nuevo o usado','grouping'=>'Agrupación incorrecta','other'=>'Otro dato'];
    public const STATUSES=['pending'=>'Pendiente','resolved'=>'Resuelta','rejected'=>'Rechazada'];
    protected $guarded=[];
    protected function casts(): array {return ['reviewed_at'=>'datetime'];}
    public function offer(){return $this->belongsTo(Offer::class);}
    public function store(){return $this->belongsTo(Store::class);}
    public function account(){return $this->belongsTo(StoreAccount::class,'store_account_id');}
}
