<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class StoreSales {
    public function attributed(int $storeId,string $from,string $to) {
        $zone=config('app.timezone');
        $start=\Carbon\Carbon::parse($from,$zone)->startOfDay()->utc()->format('Y-m-d H:i:s');
        $end=\Carbon\Carbon::parse($to,$zone)->endOfDay()->utc()->format('Y-m-d H:i:s');
        return DB::table('store_orders')->where('store_id',$storeId)->where('attributed_to_solodiscos',true)
            ->where('paid',true)->whereNotIn('status',['cancelled','failed','trash'])->whereBetween('ordered_at',[$start,$end]);
    }
    public function attributedSummary(int $storeId,string $from,string $to) {
        return $this->attributed($storeId,$from,$to)->select('currency')->selectRaw('COUNT(*) as orders, SUM(total - refunded) as net')->groupBy('currency')->orderBy('currency')->get();
    }
    public function summary(int $storeId,string $from,string $to) {
        return DB::table('store_orders')->where('store_id',$storeId)->where('attributed_to_solodiscos',true)->whereBetween('ordered_at',[$from.' 00:00:00',$to.' 23:59:59'])->where('paid',true)
            ->whereNotIn('status',['cancelled','failed','trash'])->select('currency')->selectRaw('COUNT(*) as orders, SUM(total) as gross, SUM(refunded) as refunded, SUM(total - refunded) as net, SUM(units - refunded_units) as units, AVG(total - refunded) as average')->groupBy('currency')->get();
    }
}
