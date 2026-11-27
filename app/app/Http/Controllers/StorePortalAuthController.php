<?php
namespace App\Http\Controllers;
use App\Models\{Store,StoreAccount};
use App\Services\{PortalMail,StoreDomain};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Hash,Password,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class StorePortalAuthController extends Controller {
    public function form(string $mode='login'){
        if(Auth::guard('store')->check())return redirect()->route('portal.dashboard');
        return view('portal.auth',['mode'=>$mode,'stores'=>Store::where('active',true)->orderBy('name')->get(['id','name','url']),'mailReady'=>app(PortalMail::class)->ready()]);
    }
    public function register(Request $r,StoreDomain $domains,PortalMail $mail){
        $r->merge(['email'=>strtolower(trim((string)$r->input('email')))]);
        $data=$r->validate(['store_id'=>'required|integer|exists:stores,id','name'=>'required|string|max:100','email'=>'required|email:rfc|max:254|unique:store_accounts,email',
            'password'=>['required','confirmed',PasswordRule::min(12)->letters()->numbers()]]);
        $store=Store::findOrFail($data['store_id']);
        if(!$domains->matches($store,$data['email']))throw ValidationException::withMessages(['email'=>'Usa un correo del dominio exacto de la tienda: @'.($domains->host($store)??'dominio no habilitado').'. No se admiten correos personales ni dominios parecidos.']);
        if(!$mail->ready())throw ValidationException::withMessages(['email'=>'El registro no está disponible hasta que SoloDiscos configure el envío de correos.']);
        $account=new StoreAccount(collect($data)->only('name','email','password')->all());$account->store_id=$store->id;$account->save();
        Auth::guard('store')->login($account);$r->session()->regenerate();
        return $this->sendVerification($account,$mail);
    }
    public function login(Request $r){
        $r->merge(['email'=>strtolower(trim((string)$r->input('email')))]);
        $data=$r->validate(['email'=>'required|email','password'=>'required|string']);
        $key='portal-login:'.hash('sha256',$data['email'].'|'.$r->ip());
        $ipKey='portal-login-ip:'.hash('sha256',(string)$r->ip());
        if(RateLimiter::tooManyAttempts($key,5) || RateLimiter::tooManyAttempts($ipKey,30)){
            $seconds=max(RateLimiter::availableIn($key),RateLimiter::availableIn($ipKey),1);
            throw ValidationException::withMessages(['email'=>"Demasiados intentos fallidos. Espera $seconds segundos y vuelve a intentar."]);
        }
        if(!Auth::guard('store')->attempt($data)){
            RateLimiter::hit($key,60);RateLimiter::hit($ipKey,60);
            throw ValidationException::withMessages(['email'=>'Correo o contraseña incorrectos.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();return redirect()->route('portal.dashboard');
    }
    public function verification(){return view('portal.verify',['account'=>Auth::guard('store')->user()]);}
    private function sendVerification(StoreAccount $account,PortalMail $mail){
        try{$mail->verify($account);}catch(\Throwable $e){return redirect()->route('portal.verification')->withErrors(['email'=>'No se pudo enviar la verificación. Tu cuenta sigue sin verificar; intenta reenviar cuando el administrador compruebe el correo SMTP.']);}
        return redirect()->route('portal.verification')->with('status','Enviamos el enlace de verificación a tu correo. Caduca en 60 minutos.');
    }
    public function resend(PortalMail $mail){return $this->sendVerification(Auth::guard('store')->user(),$mail);}
    public function verify(Request $r,string $id,string $hash){
        $account=Auth::guard('store')->user();
        abort_unless((string)$account->id===$id && hash_equals(sha1($account->email),$hash),403);
        if(!$account->hasVerifiedEmail())$account->markEmailAsVerified();
        return redirect()->route('portal.dashboard')->with('status','Correo verificado. Ya puedes consultar tu tienda.');
    }
    public function logout(Request $r){Auth::guard('store')->logout();$r->session()->regenerate();$r->session()->regenerateToken();return redirect()->route('portal.login');}
    public function forgot(){return view('portal.password',['reset'=>false]);}
    public function sendReset(Request $r,PortalMail $mail){
        $r->merge(['email'=>strtolower(trim((string)$r->input('email')))]);$data=$r->validate(['email'=>'required|email']);
        if(!$mail->ready())return back()->withErrors(['email'=>'El envío de correos no está habilitado.']);
        try{Password::broker('store_accounts')->sendResetLink($data);}catch(\Throwable $e){return back()->withErrors(['email'=>'No se pudo enviar el correo. Inténtalo más tarde.']);}
        return back()->with('status','Si existe una cuenta con ese correo y no se ha solicitado hace un momento, recibirás un enlace para recuperar el acceso.');
    }
    public function resetForm(Request $r,string $token){return view('portal.password',['reset'=>true,'token'=>$token,'email'=>$r->query('email')]);}
    public function reset(Request $r){
        $data=$r->validate(['email'=>'required|email','token'=>'required|string','password'=>['required','confirmed',PasswordRule::min(12)->letters()->numbers()]]);
        $status=Password::broker('store_accounts')->reset($data,function(StoreAccount $account,string $password){$account->forceFill(['password'=>Hash::make($password),'remember_token'=>Str::random(60)])->save();});
        if($status!==Password::PASSWORD_RESET)throw ValidationException::withMessages(['email'=>'El enlace no es válido o ha caducado. Solicita otro.']);
        return redirect()->route('portal.login')->with('status','Contraseña actualizada. Ingresa con la nueva contraseña.');
    }
}
