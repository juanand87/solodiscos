<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Album extends Model {
    protected $guarded = [];
    public function offers() { return $this->hasMany(Offer::class); }
}
