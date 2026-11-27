<?php
namespace App\Services;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EngagementReports {
    private function metrics(array $f){
        $q=DB::table('engagement_days as d')->whereBetween('d.day',[$f['from'],$f['to']]);
        if(!empty($f['store_id']))$q->where('d.store_id',$f['store_id']);
        if(!empty($f['format']))$q->where('d.format',$f['format']);
        return $q;
    }
    private function available(array $f){
        $q=DB::table('offers as o')->join('stores as s','s.id','=','o.store_id')->where('s.active',true)->whereNull('o.excluded_reason')->whereNull('o.missing_since')
            ->whereNotNull('o.album_id')->where('o.availability','in_stock')->where('o.price','>',0)->where('o.currency','CLP')->whereIn('o.format',['cd','vinyl'])->whereIn('o.condition',['new','used'])
            ->where('o.checked_at','>=',now()->subHours(72));
        if(!empty($f['format']))$q->where('o.format',$f['format']);return $q;
    }
    public function report(array $f): array {
        $viewKind=!empty($f['store_id'])?'product_view':'album_view';
        $base=$this->metrics($f);$counts=(clone $base)->select('d.kind')->selectRaw('SUM(d.total) as total')->groupBy('d.kind')->pluck('total','kind');
        $daily=(clone $base)->whereIn('d.kind',[$viewKind,'click'])->select('d.day','d.kind')->selectRaw('SUM(d.total) as total')->groupBy('d.day','d.kind')->get()->groupBy('day');
        $started=DB::table('engagement_state')->where('id',1)->value('started_at');
        $first=Carbon::parse($f['from'])->max(Carbon::parse($started)->startOfDay());$last=Carbon::parse($f['to'])->min(now()->startOfDay());$series=[];
        for($day=$first->copy();$day->lte($last);$day->addDay()){
            $rows=$daily->get($day->toDateString(),collect())->pluck('total','kind');
            $series[]=['day'=>$day->toDateString(),'views'=>(int)($rows[$viewKind]??0),'clicks'=>(int)($rows['click']??0)];
        }
        $top=function(string $kind)use($base){return (clone $base)->where('d.kind',$kind)->join('albums as a','a.id','=','d.album_id')
            ->select('d.album_id','d.format','a.artist','a.title')->selectRaw('SUM(d.total) as total')->groupBy('d.album_id','d.format','a.artist','a.title')->orderByDesc('total')->orderBy('d.album_id')->limit(10)->get();};
        $topProducts=(clone $base)->where('d.kind','click')->join('offers as o','o.id','=','d.offer_id')->join('stores as s','s.id','=','d.store_id')
            ->select('d.offer_id','d.store_id','d.album_id','d.format','o.title','s.name as store_name')->selectRaw('SUM(d.total) as total')
            ->groupBy('d.offer_id','d.store_id','d.album_id','d.format','o.title','s.name')->orderByDesc('total')->orderBy('d.offer_id')->limit(15)->get();
        $byStore=empty($f['store_id'])?(clone $base)->whereIn('d.kind',['product_view','click'])->join('stores as s','s.id','=','d.store_id')
            ->select('s.id','s.name')->selectRaw("SUM(CASE WHEN d.kind='click' THEN d.total ELSE 0 END) as clicks, SUM(CASE WHEN d.kind='product_view' THEN d.total ELSE 0 END) as views")
            ->groupBy('s.id','s.name')->orderByDesc('clicks')->limit(50)->get():collect();
        $interest=$this->metrics(array_diff_key($f,['store_id'=>true]))->where('d.kind','album_view')->select('d.album_id','d.format')->selectRaw('SUM(d.total) as views')->groupBy('d.album_id','d.format');
        $sales=app(CatalogStatistics::class)->query(array_diff_key($f,['store_id'=>true,'type'=>true]),false)->where('e.type','possible_sale')
            ->select('o.album_id','o.format','o.condition')->selectRaw('SUM(e.units) as units')->groupBy('o.album_id','o.format','o.condition');
        $demandAlbums=DB::query()->fromSub($interest,'interest_ids')->pluck('album_id')->merge(DB::query()->fromSub($sales,'sale_ids')->pluck('album_id'))->filter()->unique()->values()->all();
        $market=$this->available($f)->select('o.album_id','o.format','o.condition')->selectRaw('COUNT(DISTINCT o.store_id) as stores, MIN(o.price) as minimum_price')
            ->groupBy('o.album_id','o.format','o.condition');
        $scarce=DB::query()->fromSub($market,'m')->join('albums as a','a.id','=','m.album_id')
            ->leftJoinSub($interest,'i',fn($j)=>$j->on('i.album_id','=','m.album_id')->on('i.format','=','m.format'))
            ->where('m.stores','<=',2)->select('m.*','a.artist','a.title')->selectRaw('COALESCE(i.views,0) as views');
        if(!empty($f['store_id']))$scarce->whereExists(function($q)use($f){$q->selectRaw('1')->from('offers as mine')->where('mine.store_id',$f['store_id'])->whereColumn('mine.album_id','m.album_id')->whereColumn('mine.format','m.format')->whereColumn('mine.condition','m.condition');});
        $scarce=$scarce->orderBy('m.stores')->orderByDesc('views')->orderBy('m.album_id')->orderBy('m.format')->limit(15)->get();
        $out=DB::table('offers as o')->join('stores as s','s.id','=','o.store_id')->where('s.active',true)->whereNull('o.excluded_reason')->whereNull('o.missing_since')
            ->whereNotNull('o.album_id')->where('o.availability','out_of_stock')->where('o.currency','CLP')->whereIn('o.condition',['new','used'])->whereIn('o.format',['cd','vinyl'])->where('o.checked_at','>=',now()->subHours(72));
        if(!empty($f['store_id']))$out->where('o.store_id',$f['store_id']);if(!empty($f['format']))$out->where('o.format',$f['format']);
        $out->whereIn('o.album_id',$demandAlbums);
        $out->leftJoinSub($this->available($f)->whereIn('o.album_id',$demandAlbums)->select('o.store_id','o.album_id','o.format','o.condition')->distinct(),'own_available',
            fn($j)=>$j->on('own_available.store_id','=','o.store_id')->on('own_available.album_id','=','o.album_id')->on('own_available.format','=','o.format')->on('own_available.condition','=','o.condition'))
            ->whereNull('own_available.store_id');
        $out->select('o.store_id','o.album_id','o.format','o.condition','s.name as store_name')->selectRaw('MIN(o.id) as offer_id')->groupBy('o.store_id','o.album_id','o.format','o.condition','s.name');
        $replenish=empty($demandAlbums)?collect():DB::query()->fromSub($out,'x')->joinSub((clone $market)->whereIn('o.album_id',$demandAlbums),'m',fn($j)=>$j->on('x.album_id','=','m.album_id')->on('x.format','=','m.format')->on('x.condition','=','m.condition'))
            ->join('albums as a','a.id','=','x.album_id')->leftJoinSub($interest,'i',fn($j)=>$j->on('i.album_id','=','x.album_id')->on('i.format','=','x.format'))
            ->leftJoinSub($sales,'sales',fn($j)=>$j->on('sales.album_id','=','x.album_id')->on('sales.format','=','x.format')->on('sales.condition','=','x.condition'))
            ->where(fn($q)=>$q->where('i.views','>',0)->orWhere('sales.units','>',0))->select('x.*','a.artist','a.title','m.stores')->selectRaw('COALESCE(i.views,0) as views, COALESCE(sales.units,0) as units')
            ->orderByDesc('views')->orderByDesc('units')->orderBy('x.album_id')->limit(15)->get();
        return ['started'=>$started,'views'=>(int)($counts[$viewKind]??0),'clicks'=>(int)($counts['click']??0),'series'=>$series,'topViews'=>$top($viewKind),
            'topClicks'=>$topProducts,'byStore'=>$byStore,'scarce'=>$scarce,'replenish'=>$replenish];
    }
}
