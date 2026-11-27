<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ScrapeRun extends Model {
    protected $guarded = [];
    protected function casts(): array { return ['started_at'=>'datetime','heartbeat_at'=>'datetime','finished_at'=>'datetime']; }
    public function store() { return $this->belongsTo(Store::class); }
}
