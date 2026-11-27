<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CoverScan extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['started_at'=>'datetime','heartbeat_at'=>'datetime','finished_at'=>'datetime','planned_at'=>'datetime']; }
}
