<?php
namespace App\Services;
use App\Models\{Store,ScrapeRun};
use App\Jobs\ScrapeStore;
use Illuminate\Support\Facades\DB;
class ScrapeDispatcher {
    public function dispatch(Store $store): bool {
        return DB::transaction(function() use($store) {
            $store=Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (!$store->active || $store->runs()->whereIn('status',['queued','running'])->exists()) return false;
            $run=ScrapeRun::create(['store_id'=>$store->id]); ScrapeStore::dispatch($run->id)->afterCommit(); return true;
        });
    }
}
