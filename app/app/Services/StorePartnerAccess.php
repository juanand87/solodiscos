<?php
namespace App\Services;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class StorePartnerAccess {
    public function status(Store $store): array {
        $integration=DB::table('store_integrations')->where('store_id',$store->id)->first();
        $connected=$store->active && $integration && $integration->token_hash && $integration->connected_token_hash && $integration->last_connected_at
            && hash_equals($integration->token_hash,$integration->connected_token_hash) && $integration->domain===app(StoreDomain::class)->host($store);
        return ['agreement_active'=>(bool)$store->commercial_agreement_active,'plugin_connected'=>(bool)$connected,
            'full_access'=>(bool)$store->commercial_agreement_active && (bool)$connected,'last_connected_at'=>$integration?->last_connected_at];
    }
}
