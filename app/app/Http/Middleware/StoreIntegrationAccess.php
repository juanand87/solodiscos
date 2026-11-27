<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Store;
use App\Services\StoreDomain;
class StoreIntegrationAccess {
    public function handle(Request $request, Closure $next) {
        $token=$request->bearerToken();
        abort_unless(is_string($token) && strlen($token)===64,401);
        $integration=DB::table('store_integrations')->where('token_hash',hash('sha256',$token))->first();
        abort_unless($integration,401);
        $store=Store::find($integration->store_id);
        abort_unless($store && $store->active && app(StoreDomain::class)->host($store)===$integration->domain,403);
        abort_if(!$store->commercial_agreement_active && $store->commercial_agreement_terminated_at,403);
        $request->attributes->set('integration_store',$store);
        $response=$next($request);
        if($response->isSuccessful())DB::table('store_integrations')->where('id',$integration->id)->where('token_hash',$integration->token_hash)
            ->update(['last_connected_at'=>now('UTC'),'connected_token_hash'=>$integration->token_hash]);
        return $response->header('Cache-Control','no-store, private');
    }
}
