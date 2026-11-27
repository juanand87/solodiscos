<?php
namespace App\Http\Controllers;
use App\Models\Offer;
use App\Services\{StoreDomain,StoreSales,EngagementReports};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
class StoreIntegrationController extends Controller {
    public function admin(Request $r,StoreSales $sales){
        $dates=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from=$dates['from']??now('UTC')->subDays(29)->toDateString();$to=$dates['to']??now('UTC')->toDateString();
        $stores=\App\Models\Store::orderBy('name')->paginate(25)->withQueryString();
        foreach($stores as $store){$store->sales_summary=$sales->summary($store->id,$from,$to);$store->integration=DB::table('store_integrations')->where('store_id',$store->id)->first();}
        return view('integrations-admin',compact('stores','from','to'));
    }
    private function store(){return Auth::guard('store')->user()->store;}
    public function index(Request $r,StoreSales $sales){
        $store=$this->store();
        $dates=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from']);
        $from=$dates['from']??now('UTC')->subDays(29)->toDateString();$to=$dates['to']??now('UTC')->toDateString();
        return view('portal.integration',compact('store','from','to')+['integration'=>DB::table('store_integrations')->where('store_id',$store->id)->first(),'sales'=>$sales->summary($store->id,$from,$to)]);
    }
    public function rotate(){
        $store=$this->store();$token=bin2hex(random_bytes(32));
        DB::table('store_integrations')->updateOrInsert(['store_id'=>$store->id],['token_hash'=>hash('sha256',$token),'domain'=>app(StoreDomain::class)->host($store),'last_sync_at'=>null,'updated_at'=>now(),'created_at'=>now()]);
        return redirect()->route('portal.integration')->with('integration_token',$token)->with('status','Clave generada. La clave anterior dejó de funcionar. Copia esta clave ahora; se muestra una sola vez.');
    }
    public function revoke(){DB::table('store_integrations')->where('store_id',$this->store()->id)->update(['token_hash'=>null,'updated_at'=>now()]);return back()->with('status','Integración desconectada. Los datos recibidos se conservan.');}
    public function download(){return response()->download(base_path('integrations/solodiscos-woocommerce.zip'),null,['Cache-Control'=>'no-store, private']);}
    public function orders(Request $r){
        $data=$r->validate([
            'external_id'=>'required|string|max:64|regex:/^[a-zA-Z0-9_-]+$/D','status'=>'required|string|max:40|regex:/^[a-z_-]+$/D',
            'currency'=>'required|string|regex:/^[A-Z]{3}$/D','ordered_at'=>'required|date_format:Y-m-d\TH:i:s\Z',
            'source_updated_at'=>'required|date_format:Y-m-d\TH:i:s\Z','paid'=>'required|boolean',
            'total'=>'required|numeric|min:0|max:999999999999.99','refunded'=>'required|numeric|min:0|lte:total',
            'units'=>'required|integer|min:0|max:1000000','refunded_units'=>'required|integer|min:0|lte:units',
            'attributed_to_solodiscos'=>'sometimes|boolean',
            'products_total'=>'sometimes|nullable|numeric|min:0|max:999999999999.99',
            'products_refunded'=>'sometimes|nullable|numeric|min:0|lte:products_total',
            'commission_base'=>'sometimes|nullable|numeric|min:0|lte:products_total',
        ]);
        if(isset($data['commission_base'])){
            $r->validate(['products_total'=>'required|numeric','products_refunded'=>'required|numeric']);
            if(abs((float)$data['commission_base']-max(0,(float)$data['products_total']-(float)$data['products_refunded']))>0.02)throw \Illuminate\Validation\ValidationException::withMessages(['commission_base'=>'La base debe coincidir con productos menos devoluciones de productos, sin envío.']);
        }
        if(!array_key_exists('products_total',$data))$data+=['products_total'=>null,'products_refunded'=>null,'commission_base'=>null];
        $store=$r->attributes->get('integration_store');
        // All source timestamps are UTC; reporting uses the same UTC calendar in both applications.
        foreach(['ordered_at','source_updated_at'] as $field)$data[$field]=\Carbon\Carbon::parse($data[$field])->utc()->format('Y-m-d H:i:s');
        DB::transaction(function()use($data,$store){
            DB::table('store_integrations')->where('store_id',$store->id)->lockForUpdate()->first();
            $query=DB::table('store_orders')->where('store_id',$store->id)->where('external_id',$data['external_id']);$old=$query->first();
            // Legacy/other-source orders are never inserted. A previously attributed order can still receive its refund/status update.
            $attributed=isset($data['attributed_to_solodiscos'])?(bool)$data['attributed_to_solodiscos']:null;
            if($attributed!==true && (!$old || !$old->attributed_to_solodiscos))return;
            if($attributed===false){
                if($old->source_updated_at<=$data['source_updated_at'])$query->update(['attributed_to_solodiscos'=>false,'source_updated_at'=>$data['source_updated_at'],'updated_at'=>now()]);
                return;
            }
            if(!$old || $old->source_updated_at<=$data['source_updated_at']){
                if($old)$query->update($data+['updated_at'=>now()]);
                else DB::table('store_orders')->insert($data+['store_id'=>$store->id,'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('store_integrations')->where('store_id',$store->id)->update(['last_sync_at'=>now('UTC')]);
        });
        return response()->json(['received'=>true]);
    }
    public function withdraw(Request $r){
        $data=$r->validate(['external_id'=>'required|string|max:64|regex:/^[a-zA-Z0-9_-]+$/D','source_updated_at'=>'required|date_format:Y-m-d\TH:i:s\Z']);
        $at=\Carbon\Carbon::parse($data['source_updated_at'])->utc()->format('Y-m-d H:i:s');$store=$r->attributes->get('integration_store');
        DB::table('store_orders')->where('store_id',$store->id)->where('external_id',$data['external_id'])->where('source_updated_at','<=',$at)->update(['attributed_to_solodiscos'=>false,'source_updated_at'=>$at,'updated_at'=>now()]);
        return response()->json(['received'=>true]);
    }
    public function summary(Request $r,StoreSales $sales,EngagementReports $reports){
        $store=$r->attributes->get('integration_store');$from=now('UTC')->subDays(29)->toDateString();$to=now('UTC')->toDateString();
        $offers=Offer::where('store_id',$store->id);
        return response()->json(['store'=>$store->name,'from'=>$from,'to'=>$to,'timezone'=>'UTC','engagement_timezone'=>config('app.timezone'),
            'catalog'=>['total'=>(clone $offers)->count(),'published'=>(clone $offers)->available()->count(),'out_of_stock'=>(clone $offers)->where('availability','out_of_stock')->count(),
                'pending'=>(clone $offers)->whereNull('excluded_reason')->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'))->count(),
                'excluded'=>(clone $offers)->whereNotNull('excluded_reason')->count(),'last_success_at'=>$store->last_success_at?->toIso8601String()],
            'pending_corrections'=>\App\Models\StoreCorrection::where('store_id',$store->id)->where('status','pending')->count(),
            'sales'=>$sales->summary($store->id,$from,$to),'engagement'=>$reports->report(['store_id'=>$store->id,'from'=>$from,'to'=>$to]),
            'portal_url'=>route('portal.dashboard')]);
    }
    private function period(Request $r): array {
        $dates=$r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from']);
        return [$dates['from']??now()->subDays(29)->toDateString(),$dates['to']??now()->toDateString()];
    }
    public function dashboard(Request $r,StoreSales $sales){
        $store=$r->attributes->get('integration_store');[$from,$to]=$this->period($r);
        $clicks=(int)DB::table('engagement_days')->where('store_id',$store->id)->where('kind','click')->whereBetween('day',[$from,$to])->sum('total');
        $amounts=$sales->attributedSummary($store->id,$from,$to);$orders=(int)$amounts->sum('orders');
        return response()->json(['store'=>$store->name,'from'=>$from,'to'=>$to,'timezone'=>config('app.timezone'),
            'clicks'=>$clicks,'orders'=>$orders,'conversion_rate'=>$clicks>0?round(100*$orders/$clicks,2):null,'amounts'=>$amounts]);
    }
    public function attributedOrders(Request $r,StoreSales $sales){
        $r->validate(['page'=>'sometimes|integer|min:1|max:1000000']);
        $store=$r->attributes->get('integration_store');[$from,$to]=$this->period($r);
        return response()->json($sales->attributed($store->id,$from,$to)
            ->select('external_id','ordered_at','status','currency','total','refunded','products_total','products_refunded','commission_base')->selectRaw('(total - refunded) as net')
            ->orderByDesc('ordered_at')->orderByDesc('id')->paginate(20)->appends(['from'=>$from,'to'=>$to]));
    }
}
