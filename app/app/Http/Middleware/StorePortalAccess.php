<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\StoreDomain;

class StorePortalAccess {
    public function handle(Request $request,Closure $next,string $verified='yes'){
        $account=Auth::guard('store')->user();
        if(!$account)return redirect()->route('portal.login');
        abort_unless($account->store && app(StoreDomain::class)->matches($account->store,$account->email),403,'La tienda está desactivada o su dominio cambió. Consulta al administrador.');
        if($verified==='yes' && !$account->hasVerifiedEmail())return redirect()->route('portal.verification');
        $response=$next($request);$response->headers->set('Cache-Control','no-store, private');return $response;
    }
}
