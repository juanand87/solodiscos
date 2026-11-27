<?php
namespace App\Http\Controllers;
use App\Models\{Offer,StoreCorrection};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
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
    public function statistics(Request $r){
        $r->merge(['from'=>$r->input('from')?:now()->subDays(29)->toDateString(),'to'=>$r->input('to')?:now()->toDateString()]);
        $filters=$r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','format'=>['nullable',Rule::in(['cd','vinyl'])]]);
        $store=$this->store();$filters['store_id']=$store->id;
        return view('portal.statistics',compact('store','filters')+['metrics'=>app(\App\Services\EngagementReports::class)->report($filters)]);
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
        $total=(clone $query)->count();
        $sampleFilters=['format'=>$filters['format']??'','condition'=>$filters['condition']??'','mode'=>($filters['mode']??'opportunities')==='best'?'best':'opportunities'];
        $sampleKey='portal-price-preview:'.$store->id.':'.now()->toDateString().':'.hash('sha256',json_encode($sampleFilters));
        $sample=Cache::remember($sampleKey,now()->endOfDay(),fn()=>(clone $query)->inRandomOrder()->limit(5)->get()->map(fn($p)=>['album_id'=>$p->album_id,'format'=>$p->format,'condition'=>$p->condition])->all());
        $query->where(function($q)use($sample){
            if(!$sample){$q->whereRaw('1=0');return;}
            foreach($sample as $group)$q->orWhere(fn($row)=>$row->where('own.album_id',$group['album_id'])->where('own.format',$group['format'])->where('own.condition',$group['condition']));
        });
        $products=new \Illuminate\Pagination\LengthAwarePaginator($query->orderBy('a.artist')->orderBy('a.title')->orderBy('own.album_id')->orderBy('own.format')->orderBy('own.condition')->limit(5)->get(),$total,5,1);
        $groups=[];
        if($products->isNotEmpty()){
            $offers=(clone $eligible)->whereIn('o.album_id',$products->pluck('album_id')->unique()->all())
                ->select('o.id','o.album_id','o.store_id','o.format','o.condition','o.price','o.image','o.url','s.name as store_name','s.logo_url')->orderBy('o.price')->orderBy('o.id')->get();
            foreach($offers as $offer){
                $key=$offer->album_id.'|'.$offer->format.'|'.$offer->condition;
                if(!isset($groups[$key][$offer->store_id]))$groups[$key][$offer->store_id]=$offer;
            }
        }
        foreach($products as $product){
            $group=$groups[$product->album_id.'|'.$product->format.'|'.$product->condition]??[];
            $product->image=$group[$store->id]->image??null;
            if(!$product->image)foreach($group as $offer)if($offer->image){$product->image=$offer->image;break;}
            unset($group[$store->id]);
            $product->competitors=array_slice(array_values($group),0,3);
        }
        return view('portal.prices',compact('store','filters','products'));
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
