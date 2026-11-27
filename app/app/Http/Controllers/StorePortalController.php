<?php
namespace App\Http\Controllers;
use App\Models\{Offer,StoreCorrection};
use App\Services\{CatalogStatistics,OfferEvents};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Illuminate\Validation\Rule;

class StorePortalController extends Controller {
    private function store(){return Auth::guard('store')->user()->store;}
    public function dashboard(){
        $store=$this->store();
        $all=Offer::where('store_id',$store->id);
        $counts=['total'=>(clone $all)->count(),'published'=>(clone $all)->available()->count(),
            'out_of_stock'=>(clone $all)->where('availability','out_of_stock')->count(),
            'pending'=>(clone $all)->whereNull('excluded_reason')->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'))->count(),
            'excluded'=>(clone $all)->whereNotNull('excluded_reason')->count()];
        return view('portal.dashboard',compact('store','counts')+['lastRun'=>$store->runs()->latest()->first(),'pendingCorrections'=>StoreCorrection::where('store_id',$store->id)->where('status','pending')->count()]);
    }
    public function catalog(Request $r){
        $filters=$r->validate(['q'=>'nullable|string|max:150','format'=>['nullable',Rule::in(['cd','vinyl','unknown'])],
            'condition'=>['nullable',Rule::in(['new','used','unknown'])],'state'=>['nullable',Rule::in(['published','out_of_stock','pending','excluded','unpublished'])]]);
        $store=$this->store();$query=Offer::with('album')->where('store_id',$store->id);
        if(!empty($filters['q'])){$term='%'.addcslashes($filters['q'],'%_\\').'%';$query->where(fn($q)=>$q->where('title','like',$term)->orWhereHas('album',fn($a)=>$a->where('artist','like',$term)->orWhere('title','like',$term)));}
        foreach(['format','condition'] as $field)if(!empty($filters[$field]))$query->where($field,$filters[$field]);
        switch($filters['state']??''){
            case 'published':$query->available();break;
            case 'out_of_stock':$query->where('availability','out_of_stock');break;
            case 'pending':$query->whereNull('excluded_reason')->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'));break;
            case 'excluded':$query->whereNotNull('excluded_reason');break;
            case 'unpublished':$query->whereNotIn('id',Offer::available()->where('store_id',$store->id)->select('id'));break;
        }
        return view('portal.catalog',compact('store','filters')+['offers'=>$query->orderBy('title')->orderBy('id')->paginate(25)->withQueryString()]);
    }
    public function statistics(Request $r,CatalogStatistics $statistics){
        $r->merge(['from'=>$r->input('from')?:now()->subDays(29)->toDateString(),'to'=>$r->input('to')?:now()->toDateString()]);
        $filters=$r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','format'=>['nullable',Rule::in(['cd','vinyl'])],'type'=>['nullable',Rule::in(array_keys(OfferEvents::TYPES))]]);
        $store=$this->store();$filters['store_id']=$store->id;
        $topSales=$statistics->query($filters,false)->where('e.type','possible_sale')->groupBy('o.id','o.title','o.format')->select('o.id','o.title','o.format')->selectRaw('SUM(e.units) as units')->orderByDesc('units')->limit(10)->get();
        return view('portal.statistics',compact('store','filters','topSales')+$statistics->summary($filters)+['types'=>OfferEvents::TYPES,'events'=>$statistics->listing($filters)->paginate(25)->withQueryString()]);
    }
    public function corrections(){
        $store=$this->store();return view('portal.corrections',compact('store')+['corrections'=>StoreCorrection::with('offer')->where('store_id',$store->id)->latest()->paginate(20)]);
    }
    public function prices(Request $r){
        $filters=$r->validate(['format'=>['nullable',Rule::in(['cd','vinyl'])],'condition'=>['nullable',Rule::in(['new','used'])],'mode'=>['nullable',Rule::in(['opportunities','best'])]]);
        $store=$this->store();
        $eligible=DB::table('offers as o')->join('stores as s','s.id','=','o.store_id')->where('s.active',true)->whereNull('o.excluded_reason')->whereNull('o.missing_since')
            ->whereNotNull('o.album_id')->where('o.availability','in_stock')->where('o.currency','CLP')->where('o.price','>',0)->whereIn('o.format',['cd','vinyl'])->whereIn('o.condition',['new','used'])->where('o.checked_at','>=',now()->subHours(72));
        foreach(['format','condition'] as $field)if(!empty($filters[$field]))$eligible->where('o.'.$field,$filters[$field]);
        $own=(clone $eligible)->where('o.store_id',$store->id)->select('o.album_id','o.format','o.condition')->selectRaw('MIN(o.price) as price')->groupBy('o.album_id','o.format','o.condition');
        $others=(clone $eligible)->where('o.store_id','!=',$store->id)->select('o.album_id','o.format','o.condition')->selectRaw('MIN(o.price) as other_price, COUNT(DISTINCT o.store_id) as other_stores')->groupBy('o.album_id','o.format','o.condition');
        $query=DB::query()->fromSub($own,'own')->joinSub($others,'other',fn($j)=>$j->on('own.album_id','=','other.album_id')->on('own.format','=','other.format')->on('own.condition','=','other.condition'))
            ->join('albums as a','a.id','=','own.album_id')->select('own.*','other.other_price','other.other_stores','a.artist','a.title');
        if(($filters['mode']??'opportunities')==='best')$query->whereColumn('own.price','<=','other.other_price');else $query->whereColumn('own.price','>','other.other_price');
        return view('portal.prices',compact('store','filters')+['products'=>$query->orderBy('a.artist')->orderBy('a.title')->orderBy('own.album_id')->orderBy('own.format')->orderBy('own.condition')->paginate(25)->withQueryString()]);
    }
    public function correctionForm(int $offer){
        $store=$this->store();$offer=Offer::where('store_id',$store->id)->findOrFail($offer);
        return view('portal.correction-form',compact('store','offer'));
    }
    public function requestCorrection(Request $r,int $offer){
        $store=$this->store();$offer=Offer::where('store_id',$store->id)->findOrFail($offer);
        $data=$r->validate(['field'=>['required',Rule::in(array_keys(StoreCorrection::FIELDS))],'message'=>'required|string|min:10|max:4000']);
        $existing=StoreCorrection::where('store_id',$store->id)->where('offer_id',$offer->id)->where('field',$data['field'])->where('status','pending')->exists();
        if($existing)return back()->withErrors(['message'=>'Ya existe una solicitud pendiente sobre este dato del producto.']);
        StoreCorrection::create($data+['store_id'=>$store->id,'store_account_id'=>Auth::guard('store')->id(),'offer_id'=>$offer->id]);
        return redirect()->route('portal.corrections')->with('status','Solicitud registrada. El administrador revisará el dato antes de modificarlo.');
    }
}
