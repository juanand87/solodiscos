<?php
namespace App\Http\Controllers;
use App\Models\Store;
use App\Services\{CatalogStatistics,OfferEvents};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StatisticsController extends Controller {
    private function filters(Request $r): array {
        $r->merge(['from'=>$r->input('from')?:now()->subDays(29)->toDateString(),'to'=>$r->input('to')?:now()->toDateString()]);
        return $r->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'store_id'=>'nullable|integer|exists:stores,id','format'=>['nullable',Rule::in(['cd','vinyl'])],
            'type'=>['nullable',Rule::in(array_keys(OfferEvents::TYPES))],'include_initial'=>'nullable|boolean']);
    }
    public function index(Request $r,CatalogStatistics $stats) {
        $filters=$this->filters($r);
        return view('statistics',$stats->summary($filters)+['filters'=>$filters,'types'=>OfferEvents::TYPES,
            'stores'=>Store::orderBy('name')->get(['id','name','active']),'events'=>$stats->listing($filters)->paginate(30)->appends($filters)]);
    }
    public function export(Request $r,CatalogStatistics $stats) {
        $filters=$this->filters($r);
        return response()->streamDownload(function()use($stats,$filters){
            $file=fopen('php://output','w');fwrite($file,"\xEF\xBB\xBF");
            $write=function(array $row)use($file){fputcsv($file,array_map([CatalogStatistics::class,'csvCell'],$row),';','"','');};
            $write(['Fecha detección (America/Santiago)','Movimiento','Tienda','Artista','Álbum','Título tienda','Formato','Precio anterior','Precio registrado','Moneda',
                'Disponibilidad anterior','Disponibilidad registrada','Comprobación anterior','Carga inicial','Historial recuperado','URL tienda',
                'Stock anterior','Stock registrado','Posibles copias vendidas','Origen de la estimación']);
            try {foreach($stats->listing($filters)->cursor() as $event){
                $write([$event->occurred_at,OfferEvents::TYPES[$event->type],$event->store_name,$event->artist,$event->album_title,$event->title,
                    $event->format==='cd'?'CD':'Vinilo',$event->previous_price,$event->price,$event->currency??'No registrada',
                    $event->previous_availability,$event->availability,$event->previous_checked_at,$event->initial_load?'Sí':'No',$event->historical?'Sí':'No',$event->url,
                    $event->stock_before,$event->stock_after,$event->units,['reported_quantity'=>'Cantidad informada','reported_to_zero'=>'Cantidad informada a agotado confirmado','estimated_availability'=>'Disponibilidad estimada','catalog_disappearance'=>'Desaparición del catálogo'][$event->stock_evidence]??'']);
            }} finally {fclose($file);}
        },'solodiscos-estadisticas-'.$filters['from'].'-'.$filters['to'].'.csv',
            ['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff']);
    }
}
