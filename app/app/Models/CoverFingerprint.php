<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CoverFingerprint extends Model {
    protected $guarded=[];
    protected function casts(): array { return ['checked_at'=>'datetime']; }
    public function thumbnailPath(): string { return rtrim(config('covers.thumbnail_directory',storage_path('app/private/cover-thumbnails')),'/\\').'/'.$this->url_hash.'.jpg'; }
}
