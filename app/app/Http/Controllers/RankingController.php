<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\StoreRankings;
class RankingController extends Controller {
    public function sample(Request $r,\App\Models\Store $store,string $condition,StoreRankings $rankings){
        abort_unless($store->active && in_array($condition,['new','used','all']),404);
        $filters=$r->validate(['format'=>['nullable',Rule::in(['cd','vinyl'])],'currency'=>['nullable',Rule::in(['CLP'])],'page'=>'nullable|integer|min:1','cheapest'=>'nullable|boolean']);
        $filters=['format'=>$filters['format']??'','currency'=>$filters['currency']??'CLP','condition'=>$condition==='all'?'':$condition];
        if($r->boolean('cheapest'))$filters['cheapest']=true;
        return view('ranking-sample',['store'=>$store,'filters'=>$filters,'administration'=>$r->routeIs('admin.rankings.sample'),
            'products'=>$rankings->comparisonSample($store->id,$filters,(int)$r->input('page',1))]);
    }
    public function index(Request $r,StoreRankings $rankings){
        $filters=$r->validate(['format'=>['nullable',Rule::in(['cd','vinyl'])],'condition'=>['nullable',Rule::in(['new','used'])],
            'currency'=>['nullable',Rule::in(['CLP'])]]);
        $filters=['format'=>$filters['format']??'','condition'=>$filters['condition']??'','currency'=>$filters['currency']??'CLP'];
        $statistics=$r->routeIs('rankings.statistics','admin.rankings.statistics');
        return view($statistics?'ranking-statistics':'rankings',$rankings->get($filters)+['filters'=>$filters,'administration'=>$r->routeIs('admin.*')]);
    }
}
