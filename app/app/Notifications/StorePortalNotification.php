<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class StorePortalNotification extends Notification {
    public function __construct(public ?string $resetToken=null){}
    public function via($notifiable): array {return ['mail'];}
    public function toMail($account): MailMessage {
        $reset=$this->resetToken!==null;
        $url=$reset?route('portal.password.reset',['token'=>$this->resetToken,'email'=>$account->email]):URL::temporarySignedRoute('portal.verify',now()->addMinutes(60),['id'=>$account->id,'hash'=>sha1($account->email)]);
        return (new MailMessage)->mailer('store_portal')->subject($reset?'Recupera tu acceso a SoloDiscos':'Verifica tu tienda en SoloDiscos')
            ->greeting('Hola, '.$account->name)->line($reset?'Recibimos una solicitud para restablecer la contraseña de tu cuenta de tienda.':'Confirma este correo del dominio de '.$account->store->name.' para acceder a su catálogo y estadísticas.')
            ->action($reset?'Restablecer contraseña':'Verificar correo',$url)->line('El enlace caduca en 60 minutos. Si no realizaste esta solicitud, ignora este mensaje.')->salutation('SoloDiscos.cl');
    }
}
