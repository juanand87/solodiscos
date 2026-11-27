<?php
namespace App\Console\Commands;

use App\Services\StoreRankings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Date,View};
use Illuminate\Support\ViewErrorBag;

class AuditRankings extends Command {
    protected $signature='rankings:audit';
    protected $description='Audita cálculos, filtros y posiciones de todas las tiendas con SQL independiente, sin modificar el catálogo';
    private array $failures=[];
    private int $comparisons=0;
    private function equal($actual,$expected,string $context): void {
        $this->comparisons++;
        $same=is_float($actual)||is_float($expected)?($actual!==null && $expected!==null && abs($actual-$expected)<0.000001):$actual===$expected;
        if(!$same)$this->failures[]=['context'=>$context,'actual'=>$actual,'expected'=>$expected];
    }
    private function middle(array $values): ?float {
        if(!$values)return null;sort($values,SORT_NUMERIC);$size=count($values);
        $central=array_slice($values,(int)floor(($size-1)/2),$size%2?1:2);
        return array_sum($central)/count($central);
    }
    public function handle(StoreRankings $service): int {
        $clock=now()->startOfSecond();Date::setTestNow($clock);
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $report=DB::transaction(function()use($clock,$service){
                $stores=DB::table('stores')->orderBy('id')->get(['id','name','active']);$active=$stores->where('active',true)->keyBy('id');
                $cases=[];$default=null;
                foreach(['CLP','USD','EUR'] as $currency)foreach(['','cd','vinyl'] as $format)foreach(['','new','used'] as $condition){
                    $filters=compact('currency','format','condition');$context=json_encode($filters);$actual=$service->calculate($filters);
                    [$where,$bindings]=$this->criteria($filters,$clock->copy()->subHours(72)->format('Y-m-d H:i:s'));
                    $publications=(int)DB::selectOne('SELECT COUNT(*) AS n FROM offers o JOIN stores s ON s.id=o.store_id JOIN albums a ON a.id=o.album_id WHERE '.$where,$bindings)->n;
                    $this->equal($actual['offer_count'],$publications,$context.' publications');$this->equal($actual['store_count'],$active->count(),$context.' active stores');
                    $raw=DB::select("SELECT o.store_id,COUNT(DISTINCT o.album_id,o.format) AS total,
                        COUNT(DISTINCT CASE WHEN o.format='cd' THEN o.album_id END) AS cd,
                        COUNT(DISTINCT CASE WHEN o.format='vinyl' THEN o.album_id END) AS vinyl,COUNT(*) AS publications,
                        MAX(o.checked_at) AS checked_at FROM offers o JOIN stores s ON s.id=o.store_id JOIN albums a ON a.id=o.album_id
                        WHERE $where GROUP BY o.store_id",$bindings);
                    $byStore=collect($raw)->keyBy('store_id');$expectedVariety=[];
                    foreach($active as $id=>$store){
                        $entry=['id'=>(int)$id,'total'=>0,'cd'=>0,'vinyl'=>0,'publications'=>0,'checked_at'=>null];
                        if(isset($byStore[$id]))foreach(['total','cd','vinyl','publications','checked_at'] as $field)$entry[$field]=$field==='checked_at'?$byStore[$id]->$field:(int)$byStore[$id]->$field;
                        $expectedVariety[$id]=$entry;
                    }
                    $varietyOrder=array_values($expectedVariety);usort($varietyOrder,fn($a,$b)=>($b['total']<=>$a['total'])?:($a['id']<=>$b['id']));
                    $this->equal(array_column(array_column($actual['variety'],'store'),'id'),array_column($varietyOrder,'id'),$context.' variety order');
                    foreach($actual['variety'] as $row)foreach(['total','cd','vinyl','publications','checked_at'] as $field)
                        $this->equal($row[$field],$expectedVariety[$row['store']->id][$field],$context.' variety '.$row['store']->id.' '.$field);
                    // SQL, rather than the production PHP grouping, selects each store's lowest price.
                    $prices=DB::select("SELECT o.store_id,o.album_id,o.format,o.condition,MIN(o.price) AS price
                        FROM offers o JOIN stores s ON s.id=o.store_id JOIN albums a ON a.id=o.album_id
                        WHERE $where GROUP BY o.store_id,o.album_id,o.format,o.condition",$bindings);
                    $groups=[];$cheap=['new'=>[],'used'=>[]];
                    foreach(['new','used'] as $kind)foreach($active as $id=>$store)$cheap[$kind][$id]=['id'=>(int)$id,'scores'=>[],'wins'=>0,'peers'=>[],'solo'=>[]];
                    foreach($prices as $price)if(in_array($price->condition,['new','used']))$groups[$price->album_id.':'.$price->format.':'.$price->condition][]=$price;
                    foreach($groups as $peers){
                        if(count($peers)<2)continue;$lowest=min(array_column($peers,'price'));
                        foreach($peers as $i=>$price){$others=[];foreach($peers as $j=>$peer)if($i!==$j)$others[]=(float)$peer->price;
                            $cheap[$price->condition][$price->store_id]['scores'][]=(float)$price->price/$this->middle($others);
                            foreach($peers as $j=>$peer)if($i!==$j){
                                $cheap[$price->condition][$price->store_id]['peers'][$peer->store_id]=true;
                                if(count($peers)===2)$cheap[$price->condition][$price->store_id]['solo'][$peer->store_id]=($cheap[$price->condition][$price->store_id]['solo'][$peer->store_id]??0)+1;
                            }
                            if((float)$price->price===(float)$lowest)$cheap[$price->condition][$price->store_id]['wins']++;
                        }
                    }
                    foreach(['new','used'] as $kind){
                        foreach($cheap[$kind] as &$entry){$entry['sample']=count($entry['scores']);$entry['eligible']=$entry['sample']>=100;
                            $entry['index']=$entry['sample']?100*$this->middle($entry['scores']):null;
                            $entry['wins_percent']=$entry['sample']?100*$entry['wins']/$entry['sample']:0;
                            $entry['competitor_count']=count($entry['peers']);$entry['solo_comparisons']=$entry['solo']?max($entry['solo']):0;
                            $entry['solo_share']=$entry['sample']?100*$entry['solo_comparisons']/$entry['sample']:0;$entry['concentrated']=$entry['solo_share']>50;
                            unset($entry['scores'],$entry['peers'],$entry['solo']);}unset($entry);
                        foreach($actual['cheap'][$kind] as $row)foreach(['sample','eligible','index','wins','wins_percent','competitor_count','solo_comparisons','solo_share','concentrated'] as $field)
                            $this->equal($row[$field],$cheap[$kind][$row['store']->id][$field],$context.' cheap '.$kind.' '.$row['store']->id.' '.$field);
                        $order=array_values($cheap[$kind]);usort($order,fn($a,$b)=>($b['eligible']<=>$a['eligible'])?:($a['eligible']?($a['index']<=>$b['index']):($b['sample']<=>$a['sample']))?:($b['sample']<=>$a['sample'])?:($a['id']<=>$b['id']));
                        $this->equal(array_column(array_column($actual['cheap'][$kind],'store'),'id'),array_column($order,'id'),$context.' cheap '.$kind.' order');
                    }
                    // ROW_NUMBER independently selects the representative offer, including equal-price ties.
                    $deals=DB::select("SELECT r.store_id,r.album_id,r.format,MAX(100.0E0*(1-r.price*1.0E0/t.reference_price)) AS percent FROM (
                        SELECT o.*,ROW_NUMBER() OVER (PARTITION BY o.store_id,o.album_id,o.format,o.condition ORDER BY o.price,o.id) AS rn
                        FROM offers o JOIN stores s ON s.id=o.store_id JOIN albums a ON a.id=o.album_id WHERE $where
                    ) r JOIN offer_price_tracking t ON t.offer_id=r.id
                    WHERE r.rn=1 AND t.status='offer' AND t.last_available=1 AND t.reference_price>r.price AND t.last_price=r.price AND t.last_checked_at=r.checked_at
                      AND t.identity_key=SHA2(CONCAT_WS('|',r.id,r.album_id,r.format,r.condition,r.currency,r.title),256)
                    GROUP BY r.store_id,r.album_id,r.format",$bindings);
                    $discount=[];
                    foreach($active as $id=>$store){$percentages=[];foreach($deals as $deal)if($deal->store_id==$id)$percentages[]=(float)$deal->percent;
                        $n=count($percentages);$total=$expectedVariety[$id]['total'];
                        $discount[$id]=['id'=>(int)$id,'sample'=>$n,'eligible'=>$n>=10,'percent'=>$this->middle($percentages),
                            'catalog_total'=>$total,'catalog_percent'=>$total?100*$n/$total:0];
                    }
                    foreach($actual['discounts'] as $row)foreach(['sample','eligible','percent','catalog_total','catalog_percent'] as $field)
                        $this->equal($row[$field],$discount[$row['store']->id][$field],$context.' discounts '.$row['store']->id.' '.$field);
                    $order=array_values($discount);usort($order,fn($a,$b)=>($b['eligible']<=>$a['eligible'])?:($b['percent']<=>$a['percent'])?:($b['sample']<=>$a['sample'])?:($a['id']<=>$b['id']));
                    $this->equal(array_column(array_column($actual['discounts'],'store'),'id'),array_column($order,'id'),$context.' discounts order');
                    $cases[]=['filters'=>$filters,'publications'=>$publications,'classified_new'=>count(array_filter($cheap['new'],fn($v)=>$v['eligible'])),
                        'classified_used'=>count(array_filter($cheap['used'],fn($v)=>$v['eligible'])),'classified_discounts'=>count(array_filter($discount,fn($v)=>$v['eligible']))];
                    $this->renderedPositions($actual,$filters,$context);
                    if($currency==='CLP' && !$format && !$condition)$default=$this->compact($actual);
                    $this->line('Filtros comprobados: '.count($cases).'/27');
                }
                $coverage=DB::select("SELECT s.id,s.name,s.active,COUNT(o.id) AS imported,
                    SUM(CASE WHEN o.availability='in_stock' AND o.checked_at>=? AND o.missing_since IS NULL AND o.excluded_reason IS NULL AND o.album_id IS NOT NULL AND o.price>0 AND o.format IN ('cd','vinyl') THEN 1 ELSE 0 END) AS fresh_available,
                    SUM(CASE WHEN o.availability='in_stock' AND o.checked_at>=? AND o.missing_since IS NULL AND o.excluded_reason IS NULL AND o.album_id IS NOT NULL AND o.price>0 AND o.format IN ('cd','vinyl') AND o.condition='unknown' THEN 1 ELSE 0 END) AS unknown_condition
                    FROM stores s LEFT JOIN offers o ON o.store_id=s.id GROUP BY s.id,s.name,s.active ORDER BY s.id",[$clock->copy()->subHours(72),$clock->copy()->subHours(72)]);
                return ['snapshot_at'=>$clock->toIso8601String(),'stores'=>$stores->count(),'active_stores'=>$active->count(),
                    'tracking_states'=>DB::table('offer_price_tracking')->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->get()->all(),
                    'coverage'=>$coverage,'cases'=>$cases,'default'=>$default];
            });
        }catch(\Throwable $e){$this->error($e->getMessage());return self::FAILURE;}
        finally {Date::setTestNow();}
        $report+=['comparisons'=>$this->comparisons,'failures'=>$this->failures,'finished_at'=>now()->toIso8601String()];
        $path=base_path('../.local/rankings-audit.json');if(!is_dir(dirname($path)))mkdir(dirname($path),0775,true);
        file_put_contents($path,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $this->info(count($report['cases']).' combinaciones; '.$this->comparisons.' comprobaciones; '.count($this->failures).' discrepancias.');
        return $this->failures?self::FAILURE:self::SUCCESS;
    }
    private function criteria(array $filters,string $cutoff): array {
        $where="s.active=1 AND o.excluded_reason IS NULL AND o.availability='in_stock' AND o.missing_since IS NULL AND o.format IN ('cd','vinyl') AND o.price>0 AND o.checked_at>=? AND o.currency=?";
        $bindings=[$cutoff,$filters['currency']];foreach(['format','condition'] as $field)if($filters[$field]!==''){$where.=' AND o.'.$field.'=?';$bindings[]=$filters[$field];}
        return [$where,$bindings];
    }
    private function renderedPositions(array $data,array $filters,string $context): void {
        View::share('errors',new ViewErrorBag);
        foreach([false,true] as $admin){
            $html=view('ranking-statistics',$data+['filters'=>$filters,'administration'=>$admin])->render();
            $document=new \DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML('<?xml encoding="UTF-8">'.$html);libxml_clear_errors();libxml_use_internal_errors($previous);
            $xpath=new \DOMXPath($document);$tables=['variety'=>$data['variety'],'discounts'=>$data['discounts']];

            foreach($tables as $name=>$rows){
                $nodes=$xpath->query('//table[@data-ranking="'.$name.'"]/tbody/tr[@data-store-id]');
                $this->equal($nodes->length,count($rows),$context.' rendered '.$name.' rows');$position=0;
                foreach($nodes as $i=>$node){$row=$rows[$i];$this->equal((int)$node->getAttribute('data-store-id'),$row['store']->id,$context.' rendered '.$name.' order '.$i);
                    $ranked=$name==='variety'?$row['total']>0:$row['eligible'];$expected=$ranked?(string)(++$position):'';
                    $mark=$xpath->query('.//span[@class="ranking-position"]',$node)->item(0);
                    $this->equal($mark?trim($mark->textContent):'',$expected,$context.' rendered '.$name.' position '.$i);
                }
            }
        }
    }
    private function compact(array $data): array {
        $convert=fn($rows)=>array_map(function($row){$store=$row['store'];unset($row['store']);return ['store_id'=>$store->id,'store_name'=>$store->name]+$row;},$rows);
        return ['variety'=>$convert($data['variety']),'cheap_new'=>$convert($data['cheap']['new']),'cheap_used'=>$convert($data['cheap']['used']),'discounts'=>$convert($data['discounts'])];
    }
}
