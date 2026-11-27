<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class StoreSales {
    public function summary(int $storeId,string $from,string $to) {
        return DB::table('store_orders')->where('store_id',$storeId)->whereBetween('ordered_at',[$from.' 00:00:00',$to.' 23:59:59'])->where('paid',true)
            ->whereNotIn('status',['cancelled','failed','trash'])->select('currency')->selectRaw('COUNT(*) as orders, SUM(total) as gross, SUM(refunded) as refunded, SUM(total - refunded) as net, SUM(units - refunded_units) as units, AVG(total - refunded) as average')->groupBy('currency')->get();
    }
}
