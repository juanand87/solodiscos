<?php
namespace App\Console\Commands;
use App\Models\Offer;
use App\Services\StockQuantities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedStockQuantities extends Command {
    protected $signature='stocks:baseline';
    protected $description='Inicializa cantidades desde la última disponibilidad conocida, sin generar ventas históricas';
    public function handle(StockQuantities $quantities): int {
        $total=0;
        Offer::whereNull('stock_quantity')->orderBy('id')->chunkById(500,function($offers)use($quantities,&$total){
            $groups=[];
            foreach($offers as $offer){
                $r=$offer->missing_since?['stock_quantity'=>0,'stock_quantity_source'=>'missing']:$quantities->reading($offer->availability,$offer->source_metadata??[]);
                $key=($r['stock_quantity']??'null').'|'.$r['stock_quantity_source'];$groups[$key]['reading']=$r;$groups[$key]['ids'][]=$offer->id;
            }
            foreach($groups as $g)$total+=DB::table('offers')->whereIn('id',$g['ids'])->whereNull('stock_quantity')->update($g['reading']+['stock_quantity_checked_at'=>DB::raw('COALESCE(missing_since,checked_at,created_at)')]);
        });
        $this->info($total.' referencias de stock inicializadas. No se crearon movimientos ni posibles ventas.');return self::SUCCESS;
    }
}
