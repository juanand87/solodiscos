<?php
namespace App\Http\Controllers;
use App\Models\{User,Store,Offer,Album,ScrapeRun};
use App\Services\{StoreClient,ProductNormalizer,ScrapeDispatcher,StoreBranding};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Hash,RateLimiter};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class AdminController extends Controller {
    public function login() { return view('auth',['setup'=>!User::exists()]); }
    public function authenticate(Request $r) {
        $data=$r->validate(['email'=>'required|email','password'=>'required|string']); $key='login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key,5)) throw ValidationException::withMessages(['email'=>'Demasiados intentos. Espera un minuto.']);
        RateLimiter::hit($key,60);
        if (!User::exists()) {
            abort_unless(app()->isLocal() && in_array($r->ip(),['127.0.0.1','::1']),403);
            $r->validate(['password'=>'required|min:12|confirmed','name'=>'required|max:100']);
            User::create(['name'=>$r->name,'email'=>$data['email'],'password'=>Hash::make($data['password'])]);
        }
        if (!Auth::attempt($data)) throw ValidationException::withMessages(['email'=>'Correo o contraseña incorrectos.']);
        RateLimiter::clear($key); $r->session()->regenerate(); return redirect()->route('admin');
    }
    public function logout(Request $r) { Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/'); }
    private function dashboardData(): array {
        $active=ScrapeRun::with('store')->whereIn('status',['queued','running'])->latest()->get();
        $recent=ScrapeRun::with('store')->whereNotIn('status',['queued','running'])->latest()->limit(15)->get();
        return ['stores'=>Store::withCount('offers')->latest()->get(),'runs'=>$active->concat($recent),'offerCount'=>Offer::count(),
            'pending'=>Offer::whereNull('excluded_reason')->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'))->count()];
    }
    public function index() { return view('admin',$this->dashboardData()); }
    public function progress() {
        $data=$this->dashboardData();
        return response()->json(['html'=>view('admin-live',$data)->render(),'active_runs'=>$data['runs']->whereIn('status',['queued','running'])->count()])->header('Cache-Control','no-store, private');
    }
    public function form(?Store $store=null) { return view('store-form',['store'=>$store??new Store(['interval_hours'=>48,'active'=>true,'currency'=>'CLP','default_condition'=>'unknown'])]); }
    public function save(Request $r,StoreClient $client,?Store $store=null) {
        $url=rtrim(trim((string)$r->input('url')),'/');
        if($r->input('platform')==='woocommerce')$url=preg_replace('~/(?:tienda|shop)$~i','',$url);
        $r->merge(['url'=>$url]);
        $duplicateRule=function($attribute,$value,$fail)use($store){
            $duplicate=app(\App\Services\StoreDuplicates::class)->find($value,$store?->id);
            if($duplicate){
                $state=$duplicate->active?'activa':'pausada';
                $fail('Esta tienda ya está registrada como «'.$duplicate->name.'» ('.$duplicate->url.'), '.$state.'. Usa su registro existente para actualizar el catálogo.');
            }
        };
        $data=$r->validate(['name'=>'required|string|max:150','url'=>['bail','required','url:http,https','max:255',$duplicateRule],'platform'=>['required',Rule::in(['shopify','woocommerce','bsale'])],
            'currency'=>['required',Rule::in(['CLP','USD','EUR'])],'default_condition'=>['required',Rule::in(['unknown','new','used','category'])],
            'new_condition_categories'=>'nullable|string|max:5000','used_condition_categories'=>'nullable|string|max:5000',
            'category_condition_fallback'=>['sometimes','required',Rule::in(['unknown','new','used'])],
            'interval_hours'=>'required|integer|min:6|max:168','active'=>'sometimes|boolean','logo_url'=>'nullable|url:http,https|max:2000'],
            ['url.unique'=>'Esta tienda ya está registrada. Usa el registro existente para actualizar su catálogo.']);
        if($data['default_condition']==='category'){
            $categories=app(\App\Services\CategoryConditions::class);
            $new=$categories->rules($data['new_condition_categories']??null);
            $used=$categories->rules($data['used_condition_categories']??null);
            if(!$new && !$used)throw ValidationException::withMessages(['new_condition_categories'=>'Indica al menos una categoría de nuevos o usados.']);
            if(array_intersect($new,$used))throw ValidationException::withMessages(['used_condition_categories'=>'Una misma categoría no puede indicar nuevo y usado a la vez.']);
        }
        try { $client->validateUrl($data['url']); } catch (\Throwable $e) { throw ValidationException::withMessages(['url'=>$e->getMessage()]); }
        if ($store?->exists && $store->offers()->exists() && ($store->url!==$data['url'] || $store->platform!==$data['platform'])) {
            throw ValidationException::withMessages(['url'=>'Para cambiar el dominio o la plataforma de una tienda con productos, pausa esta fuente y agrega una tienda nueva.']);
        }
        $data['url']=rtrim($data['url'],'/'); $data['active']=$r->boolean('active');
        $updated=app(\App\Services\StoreConditions::class)->configure($store??new Store,$data);
        return redirect()->route('admin')->with('status',"Tienda guardada. Condición actualizada en $updated publicaciones; los rankings se recalcularán automáticamente.");
    }
    public function scrape(Store $store,ScrapeDispatcher $dispatcher) {
        $ok=$dispatcher->dispatch($store); return back()->with('status',$ok?'Extracción en cola. El trabajador la procesará.':'La tienda está pausada o ya tiene una extracción pendiente.');
    }
    public function refreshLogo(Store $store,StoreBranding $branding) {
        try { $logo=$branding->discover($store); return back()->with('status',$logo?'Logo actualizado desde el sitio de la tienda.':'No se encontró un logo. Puedes indicar su URL manualmente.'); }
        catch (\Throwable $e) { return back()->with('status','No se pudo consultar el logo: '.$e->getMessage()); }
    }
    public function offers(Request $r) {
        $query=Offer::with(['store','album']);
        if ($r->filled('q')) $query->where('title','like','%'.$r->string('q').'%');
        if ($r->boolean('pending')) $query->whereNull('excluded_reason')->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'));
        return view('offers',['offers'=>$query->latest()->paginate(20)->withQueryString()]);
    }
    public function editOffer(Offer $offer) { return view('offer-form',compact('offer')); }
    public function saveOffer(Request $r,Offer $offer,ProductNormalizer $normalizer) {
        if ($offer->excluded_reason) throw ValidationException::withMessages(['format'=>'Este producto está excluido: '.$offer->excluded_reason.' No se publican libros ni accesorios.']);
        $data=$r->validate(['artist'=>'required|string|max:255','album'=>'required|string|max:255','format'=>['required',Rule::in(['cd','vinyl','unknown'])],'condition'=>['required',Rule::in(['new','used','unknown'])]]);
        $mode=$offer->store->default_condition;
        if(in_array($mode,['new','used']) && $data['condition']!==$mode)
            throw ValidationException::withMessages(['condition'=>'La condición está fijada por la configuración de la tienda. Cambia su modo a automático para editarla individualmente.']);
        $album=Album::firstOrCreate(['match_key'=>$normalizer->key($data['artist'],$data['album'])],['artist'=>$data['artist'],'title'=>$data['album']]);
        $offer->update(['album_id'=>$album->id,'format'=>$data['format'],'condition'=>$data['condition'],'reviewed'=>true]);
        \App\Services\PriceHistory::invalidate();
        return redirect()->route('offers')->with('status','Clasificación guardada y protegida de futuras importaciones.');
    }
}
