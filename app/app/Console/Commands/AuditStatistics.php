<?php
namespace App\Console\Commands;

use App\Http\Controllers\StatisticsController;
use App\Services\{CatalogStatistics,OfferEvents};
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,View};
use Illuminate\Support\ViewErrorBag;

class AuditStatistics extends Command {
    protected $signature='stats:audit';
    protected $description='Contrasta estadísticas y CSV con una instantánea de los datos reales, sin modificar el catálogo';
    private array $checks=[];
    private array $failures=[];
    private string $directory;

    public function handle(CatalogStatistics $stats,StatisticsController $controller): int {
        $this->directory=base_path('../.local');
        if(!is_dir($this->directory))mkdir($this->directory,0775,true);
        $started=now()->toIso8601String();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $report=DB::transaction(function()use($stats,$controller){
                // The first read fixes the snapshot; background imports can continue independently.
                $stores=DB::table('stores')->orderBy('id')->get(['id','name','active']);
                $bounds=DB::table('offer_events')->selectRaw('MIN(occurred_at) AS first_date, MAX(occurred_at) AS last_date')->first();
                $from=substr($bounds->first_date??now()->toDateString(),0,10);$to=substr($bounds->last_date??now()->toDateString(),0,10);
                $cases=[];
                $add=function(array $filters)use(&$cases){ksort($filters);$cases[json_encode($filters)]=$filters;};
                foreach([null,...$stores->pluck('id')->all()] as $store){
                    foreach([false,true] as $initial)foreach([null,'cd','vinyl'] as $format){
                        $filters=['from'=>$from,'to'=>$to,'include_initial'=>(int)$initial];
                        if($store!==null)$filters['store_id']=$store;if($format)$filters['format']=$format;$add($filters);
                    }
                }
                foreach(array_keys(OfferEvents::TYPES) as $type)foreach([0,1] as $initial)foreach([null,'cd','vinyl'] as $format){
                    $filters=['from'=>$from,'to'=>$to,'include_initial'=>$initial,'type'=>$type];if($format)$filters['format']=$format;$add($filters);
                }
                foreach(array_unique([$from,$to,Carbon::parse($to)->addDay()->toDateString()]) as $day){
                    foreach([0,1] as $initial)foreach([null,'cd','vinyl'] as $format){
                        $filters=['from'=>$day,'to'=>$day,'include_initial'=>$initial];if($format)$filters['format']=$format;$add($filters);
                    }
                }
                $this->info('Comprobando '.count($cases).' combinaciones de filtros y exportaciones con una instantánea consistente.');
                $rendered=0;
                foreach($cases as $filters){
                    [$where,$bindings]=$this->conditions($filters);
                    $expected=(int)DB::selectOne('SELECT COUNT(*) AS n FROM offer_events e JOIN offers o ON o.id=e.offer_id WHERE '.$where,$bindings)->n;
                    $summary=$stats->summary($filters);$allFilters=$filters;unset($allFilters['type']);
                    [$allWhere,$allBindings]=$this->conditions($allFilters);
                    $rawCounts=DB::select('SELECT e.type,COUNT(*) AS n FROM offer_events e JOIN offers o ON o.id=e.offer_id WHERE '.$allWhere.' GROUP BY e.type',$allBindings);
                    foreach(OfferEvents::TYPES as $type=>$label){
                        $raw=collect($rawCounts)->firstWhere('type',$type);
                        if((int)($summary['counts'][$type]??0)!==(int)($raw->n??0))$this->failures[]=['filters'=>$filters,'error'=>'Resumen incorrecto: '.$type];
                    }
                    $storeTotal=$summary['byStore']->sum(fn($row)=>$row['counts']->sum());
                    if($storeTotal!==array_sum(array_map(fn($row)=>(int)$row->n,$rawCounts)))$this->failures[]=['filters'=>$filters,'error'=>'Suma por tienda incorrecta'];
                    $rawStores=DB::select('SELECT e.store_id,e.type,COUNT(*) AS n FROM offer_events e JOIN offers o ON o.id=e.offer_id WHERE '.$allWhere.' GROUP BY e.store_id,e.type',$allBindings);
                    foreach($rawStores as $row){
                        if((int)($summary['byStore'][$row->store_id]['counts'][$row->type]??0)!==(int)$row->n)
                            $this->failures[]=['filters'=>$filters,'error'=>'Desglose por tienda incorrecto','store_id'=>$row->store_id,'type'=>$row->type];
                    }
                    $request=Request::create('/admin/estadisticas','GET',$filters);
                    $view=$controller->index($request,$stats);$data=$view->getData();
                    if($data['events']->total()!==$expected)$this->failures[]=['filters'=>$filters,'error'=>'Total paginado incorrecto'];
                    $export=$controller->export(Request::create('/admin/estadisticas/exportar','GET',$filters),$stats);
                    $file=tmpfile();$level=ob_get_level();
                    try {
                        ob_start(function($chunk)use($file){fwrite($file,$chunk);return '';},1048576);
                        try {$export->sendContent();}finally{while(ob_get_level()>$level)ob_end_flush();}
                        rewind($file);if(fread($file,3)!=="\xEF\xBB\xBF")$this->failures[]=['filters'=>$filters,'error'=>'CSV sin BOM UTF-8'];
                        $header=fgetcsv($file,null,';','"','');$rows=0;$malformed=0;$outside=0;$unsafe=0;
                        while(($row=fgetcsv($file,null,';','"',''))!==false){
                            $rows++;if(count($row)!==16){$malformed++;continue;}
                            if(!mb_check_encoding(implode('',$row),'UTF-8'))$malformed++;
                            foreach($row as $cell)if(preg_match('/^(?:[\t\r\n]|\s*[=+\-@])/u',$cell))$unsafe++;
                            if(substr($row[0],0,10)<$filters['from'] || substr($row[0],0,10)>$filters['to'])$outside++;
                            if(isset($filters['format']) && $row[6]!==($filters['format']==='cd'?'CD':'Vinilo'))$outside++;
                            if(isset($filters['type']) && $row[1]!==OfferEvents::TYPES[$filters['type']])$outside++;
                            if(empty($filters['include_initial']) && $row[13]!=='No')$outside++;
                            if(isset($filters['store_id']) && $row[2]!==CatalogStatistics::csvCell($stores->firstWhere('id',$filters['store_id'])->name))$outside++;
                        }
                        if(count($header?:[])!==16 || $rows!==$expected || $malformed || $outside || $unsafe)
                            $this->failures[]=['filters'=>$filters,'error'=>'CSV inconsistente','expected'=>$expected,'actual'=>$rows,'malformed'=>$malformed,'outside'=>$outside,'unsafe'=>$unsafe];
                        if(!isset($filters['store_id']) && !isset($filters['format']) && !isset($filters['type']) && $filters['include_initial'] && $filters['from']===$from && $filters['to']===$to){
                            rewind($file);$saved=fopen($this->directory.'/estadisticas-audit-en-curso.csv','w');stream_copy_to_stream($file,$saved);fclose($saved);
                        }
                    }finally{fclose($file);}
                    if($rendered<3 && !isset($filters['format']) && $filters['include_initial']){
                        $original=app('request');app()->instance('request',$request);View::share('errors',new ViewErrorBag);
                        try {
                            $html=$view->render();if(!str_contains($html,'Exportar a CSV'))$this->failures[]=['filters'=>$filters,'error'=>'Vista sin exportación'];
                            $rendered++;
                        }finally{app()->instance('request',$original);}
                    }
                    $this->checks[]=['filters'=>$filters,'expected'=>$expected,'csv_rows'=>$rows];
                    if(count($this->checks)%30===0)$this->line('Combinaciones comprobadas: '.count($this->checks));
                }
                $missing=(int)DB::selectOne("SELECT COUNT(*) AS n FROM offers o LEFT JOIN offer_events e ON e.event_key=CONCAT('added:',o.id) WHERE e.id IS NULL")->n;
                if($missing)$this->failures[]=['error'=>'Ofertas sin evento de incorporación','count'=>$missing];
                $transitions=DB::select("SELECT t.*, e.id AS event_id FROM (
                    SELECT id, offer_id, availability, price, currency,
                      LAG(availability) OVER (PARTITION BY offer_id ORDER BY id) AS old_stock,
                      LAG(price) OVER (PARTITION BY offer_id ORDER BY id) AS old_price,
                      LAG(currency) OVER (PARTITION BY offer_id ORDER BY id) AS old_currency
                    FROM offer_observations
                ) t LEFT JOIN offer_events e ON e.event_key=CONCAT('observation:',t.id,':',
                    CASE WHEN t.old_stock='in_stock' AND t.availability='out_of_stock' THEN 'out_of_stock'
                         WHEN t.old_stock='out_of_stock' AND t.availability='in_stock' THEN 'restocked' ELSE 'none' END)
                WHERE (t.old_stock='in_stock' AND t.availability='out_of_stock') OR (t.old_stock='out_of_stock' AND t.availability='in_stock')");
                foreach($transitions as $transition)if(!$transition->event_id)$this->failures[]=['error'=>'Transición de stock sin evento','observation'=>$transition->id];
                $priceMissing=(int)DB::selectOne("SELECT COUNT(*) AS n FROM (
                    SELECT id,price,currency,LAG(price) OVER (PARTITION BY offer_id ORDER BY id) AS old_price,
                    LAG(currency) OVER (PARTITION BY offer_id ORDER BY id) AS old_currency FROM offer_observations
                ) t LEFT JOIN offer_events e ON e.event_key=CONCAT('observation:',t.id,':price_changed')
                WHERE t.price IS NOT NULL AND t.old_price IS NOT NULL AND t.price<>t.old_price
                  AND (t.currency=t.old_currency OR (t.currency IS NULL AND t.old_currency IS NULL)) AND e.id IS NULL")->n;
                if($priceMissing)$this->failures[]=['error'=>'Variaciones de precio sin evento','count'=>$priceMissing];
                return ['stores'=>$stores->all(),'offers'=>DB::table('offers')->count(),'events'=>DB::table('offer_events')->count(),
                    'eligible_offers'=>DB::table('offers')->whereNull('excluded_reason')->whereIn('format',['cd','vinyl'])->count(),
                    'from'=>$from,'to'=>$to,'stock_transitions'=>count($transitions),'missing_added'=>$missing,'missing_price_changes'=>$priceMissing,
                    'rendered_views'=>$rendered,'totals'=>DB::table('offer_events')->select('type','initial_load')->selectRaw('COUNT(*) AS n')->groupBy('type','initial_load')->get()->all()];
            });
        }catch(\Throwable $e){
            file_put_contents($this->directory.'/statistics-audit-interrupted.json',json_encode(['status'=>'interrupted','started_at'=>$started,
                'finished_at'=>now()->toIso8601String(),'error'=>$e->getMessage(),'completed_checks'=>count($this->checks)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $this->error($e->getMessage());return self::FAILURE;
        }
        $report+=['started_at'=>$started,'finished_at'=>now()->toIso8601String(),'checks'=>$this->checks,'failures'=>$this->failures];
        file_put_contents($this->directory.'/statistics-audit.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if(!$this->failures && is_file($this->directory.'/estadisticas-audit-en-curso.csv'))
            rename($this->directory.'/estadisticas-audit-en-curso.csv',$this->directory.'/estadisticas-todas-las-tiendas.csv');
        $this->info(count($this->checks).' combinaciones; '.count($this->failures).' discrepancias. Informe: .local/statistics-audit.json');
        return $this->failures?self::FAILURE:self::SUCCESS;
    }
    private function conditions(array $filters): array {
        $where="o.excluded_reason IS NULL AND o.format IN ('cd','vinyl') AND e.occurred_at>=? AND e.occurred_at<?";
        $bindings=[$filters['from'].' 00:00:00',Carbon::parse($filters['to'])->addDay()->format('Y-m-d').' 00:00:00'];
        foreach(['store_id'=>'e.store_id','format'=>'o.format','type'=>'e.type'] as $key=>$column)
            if(!empty($filters[$key])){$where.=' AND '.$column.'=?';$bindings[]=$filters[$key];}
        if(empty($filters['include_initial']))$where.=' AND e.initial_load=0';
        return [$where,$bindings];
    }
}
