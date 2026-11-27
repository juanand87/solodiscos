<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Store extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['active'=>'boolean','last_attempt_at'=>'datetime','last_success_at'=>'datetime','cycle_started_at'=>'datetime','commercial_agreement_active'=>'boolean','commercial_agreement_activated_at'=>'datetime']; }
    public function offers() { return $this->hasMany(Offer::class); }
    public function runs() { return $this->hasMany(ScrapeRun::class); }
}
