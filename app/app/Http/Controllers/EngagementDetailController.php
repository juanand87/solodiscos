<?php
namespace App\Http\Controllers;

use App\Models\{Album,Offer};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use Carbon\Carbon;

class EngagementDetailController extends Controller {
    public function __invoke(Request $request,Album $album,string $format){
        $input=$request->validate(['store_id'=>'nullable|integer|exists:stores,id','offer_id'=>'nullable|integer']);
        $portal=$request->routeIs('portal.*');
        $storeId=$portal?Auth::guard('store')->user()->store_id:($input['store_id']??null);
        $offers=Offer::where('album_id',$album->id)->where('format',$format);
        if($storeId)$offers->where('store_id',$storeId);
        if(!empty($input['offer_id']))$offers->whereKey($input['offer_id']);
        $offer=$offers->orderByRaw('image IS NULL')->orderBy('id')->firstOrFail();
        $from=now()->startOfMonth()->subMonths(5);$to=now()->toDateString();
        $query=DB::table('engagement_days')->where('kind','click')->where('album_id',$album->id)->where('format',$format)->whereBetween('day',[$from->toDateString(),$to]);
        if($storeId)$query->where('store_id',$storeId);
        if(!empty($input['offer_id']))$query->where('offer_id',$offer->id);
        $days=$query->select('day')->selectRaw('SUM(total) as total')->groupBy('day')->get();
        $months=$days->groupBy(fn($row)=>substr($row->day,0,7))->map(fn($rows)=>(int)$rows->sum('total'));
        $started=DB::table('engagement_state')->where('id',1)->value('started_at');$series=[];
        for($i=0;$i<6;$i++){$month=$from->copy()->addMonths($i);$series[]=['month'=>$month->format('Y-m'),'clicks'=>$months[$month->format('Y-m')]??0,'measured'=>$started && $month->copy()->endOfMonth()->gte(Carbon::parse($started))];}
        return response()->json(['artist'=>$album->artist,'title'=>$album->title,'image'=>$offer->image,'format'=>$format==='cd'?'CD':'Vinilo','scope'=>$storeId?$offer->store->name:'Todas las tiendas','from'=>$from->toDateString(),'to'=>$to,'started_at'=>$started,'total'=>(int)$days->sum('total'),'months'=>$series])->header('Cache-Control','no-store, private');
    }
}
