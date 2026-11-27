<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CatalogStatistics {
    public function query(array $filters,bool $withType=true) {
        $query=DB::table('offer_events as e')->join('offers as o','o.id','=','e.offer_id')
            ->join('stores as s','s.id','=','e.store_id')->leftJoin('albums as a','a.id','=','o.album_id')
            ->whereNull('o.excluded_reason')->whereIn('o.format',['cd','vinyl'])
            ->where('e.occurred_at','>=',Carbon::parse($filters['from'])->startOfDay()->format('Y-m-d H:i:s'))
            ->where('e.occurred_at','<',Carbon::parse($filters['to'])->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        if(!empty($filters['store_id']))$query->where('e.store_id',$filters['store_id']);
        if(!empty($filters['format']))$query->where('o.format',$filters['format']);
        if(empty($filters['include_initial']))$query->where('e.initial_load',false)
            ->where(fn($q)=>$q->where('e.type','!=','added')->orWhere('e.availability','in_stock'));
        if($withType && !empty($filters['type']))$query->where('e.type',$filters['type']);
        return $query;
    }
    public function listing(array $filters) {
        return $this->query($filters)->select('e.*','o.title','o.format','o.url','o.image','a.artist','a.title as album_title','s.name as store_name')
            ->orderByDesc('e.occurred_at')->orderByDesc('e.id');
    }
    public function summary(array $filters): array {
        $aggregate="SUM(CASE WHEN e.type='possible_sale' THEN COALESCE(e.units,0) ELSE 1 END) AS total";
        $counts=$this->query($filters,false)->selectRaw('e.type, '.$aggregate)->groupBy('e.type')->pluck('total','type');
        $stores=$this->query($filters,false)->select('s.id','s.name','e.type')->selectRaw($aggregate)->groupBy('s.id','s.name','e.type')->get();
        return ['counts'=>$counts,'byStore'=>$stores->groupBy('id')->map(function($rows){
            return ['id'=>$rows->first()->id,'name'=>$rows->first()->name,'counts'=>$rows->pluck('total','type')];
        })];
    }
    public static function csvCell($value): string {
        $value=(string)($value??'');
        return preg_match('/^(?:[\t\r\n]|\s*[=+\-@])/u',$value)?"'".$value:$value;
    }
}
