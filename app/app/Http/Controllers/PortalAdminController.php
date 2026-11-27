<?php
namespace App\Http\Controllers;
use App\Models\{SmtpSetting,StoreCorrection};
use App\Services\PortalMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Mail};
use Illuminate\Validation\Rule;

class PortalAdminController extends Controller {
    public function smtp(){return view('portal.admin-smtp',['settings'=>SmtpSetting::find(1)]);}
    public function saveSmtp(Request $r){
        $data=$r->validate(['enabled'=>'nullable|boolean','host'=>['required','string','max:253','regex:/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D'],
            'port'=>'required|integer|between:1,65535','scheme'=>['required',Rule::in(['smtp','smtps'])],'username'=>'required|string|max:255',
            'password'=>'nullable|string|max:1000','from_address'=>'required|email:rfc|max:254','from_name'=>'required|string|max:100']);
        $settings=SmtpSetting::find(1)??new SmtpSetting(['id'=>1]);
        if(empty($data['password']))unset($data['password']);
        if(!$settings->exists && !isset($data['password']))return back()->withErrors(['password'=>'Ingresa la contraseña SMTP para guardar la configuración.']);
        $settings->fill($data);$settings->enabled=$r->boolean('enabled');$settings->save();
        return back()->with('status','Configuración SMTP guardada. La contraseña se conserva cifrada y no se muestra en pantalla.');
    }
    public function testSmtp(Request $r,PortalMail $mail){
        $data=$r->validate(['test_email'=>'required|email:rfc|max:254']);
        try{$mail->configure();Mail::mailer('store_portal')->raw('Este correo confirma el envío de prueba desde SoloDiscos.cl.',fn($message)=>$message->to($data['test_email'])->subject('Prueba SMTP · SoloDiscos'));}
        catch(\Throwable $e){return back()->withErrors(['smtp'=>'No se pudo enviar la prueba. Comprueba host, puerto, seguridad, usuario y contraseña. No se muestran detalles que puedan contener credenciales.']);}
        return back()->with('status','El servidor SMTP aceptó el correo de prueba. Comprueba su recepción y la carpeta de spam.');
    }
    public function corrections(Request $r){
        $data=$r->validate(['status'=>['nullable',Rule::in(array_keys(StoreCorrection::STATUSES))]]);$status=$data['status']??'pending';
        return view('portal.admin-corrections',['status'=>$status,'corrections'=>StoreCorrection::with(['store','offer','account'])->where('status',$status)->latest()->paginate(25)->withQueryString()]);
    }
    public function review(Request $r,int $correction){
        $data=$r->validate(['status'=>['required',Rule::in(['resolved','rejected'])],'response'=>'required|string|min:5|max:4000']);
        $updated=StoreCorrection::whereKey($correction)->where('status','pending')->update($data+['reviewed_by'=>Auth::guard('web')->id(),'reviewed_at'=>now(),'updated_at'=>now()]);
        abort_unless($updated,409,'Esta solicitud ya fue revisada o no existe.');
        return back()->with('status','Respuesta guardada. La tienda puede verla en su portal.');
    }
}
