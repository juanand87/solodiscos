<?php
namespace App\Services;
use App\Models\{SmtpSetting,StoreAccount};
use App\Notifications\StorePortalNotification;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class PortalMail {
    public function ready(): bool {return SmtpSetting::whereKey(1)->where('enabled',true)->exists();}
    public function configure(): void {
        $settings=SmtpSetting::find(1);
        if(!$settings?->enabled)throw new RuntimeException('El envío de correos del portal todavía no está habilitado.');
        config(['mail.mailers.store_portal'=>['transport'=>'smtp','scheme'=>$settings->scheme,'host'=>$settings->host,'port'=>$settings->port,
            'username'=>$settings->username,'password'=>$settings->password,'timeout'=>15,'auto_tls'=>true,'require_tls'=>true],
            'mail.from'=>['address'=>$settings->from_address,'name'=>$settings->from_name]]);
        Mail::purge('store_portal');
    }
    public function verify(StoreAccount $account): void {$this->configure();$account->notify(new StorePortalNotification);}
    public function reset(StoreAccount $account,string $token): void {$this->configure();$account->notify(new StorePortalNotification($token));}
}
