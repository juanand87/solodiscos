<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Offer extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['checked_at'=>'datetime','stock_quantity_checked_at'=>'datetime','stock_quantity'=>'integer','reviewed'=>'boolean','source_metadata'=>'array']; }
    public function store() { return $this->belongsTo(Store::class); }
    public function album() { return $this->belongsTo(Album::class); }
    public function scopeAvailable($query) { return $query->whereNull('excluded_reason')->where('availability','in_stock')->whereNull('missing_since')->whereNotNull('album_id')->whereIn('format',['cd','vinyl'])->where('price','>',0)->where('checked_at','>=',now()->subDays(7))->whereHas('store', fn($q)=>$q->where('active',true)); }
    public function isPublished(): bool { return !$this->excluded_reason && $this->availability==='in_stock' && !$this->missing_since && $this->album_id && in_array($this->format,['cd','vinyl']) && $this->price>0 && $this->checked_at?->gte(now()->subDays(7)) && $this->store->active; }
}
