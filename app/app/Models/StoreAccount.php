<?php
namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class StoreAccount extends Authenticatable implements MustVerifyEmail {
    use Notifiable;
    protected $fillable=['name','email','password'];
    protected $hidden=['password','remember_token'];
    protected function casts(): array {return ['password'=>'hashed','email_verified_at'=>'datetime'];}
    public function store(){return $this->belongsTo(Store::class);}
    public function sendPasswordResetNotification($token){app(\App\Services\PortalMail::class)->reset($this,$token);}
}
