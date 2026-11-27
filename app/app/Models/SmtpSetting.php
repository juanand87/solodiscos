<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SmtpSetting extends Model {
    protected $guarded=[];
    protected $hidden=['password'];
    protected function casts(): array {return ['enabled'=>'boolean','password'=>'encrypted'];}
}
