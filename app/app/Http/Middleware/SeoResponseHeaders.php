<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class SeoResponseHeaders {
    public function handle(Request $request,Closure $next){
        $response=$next($request);
        if($request->is('admin','admin/*','tiendas','tiendas/*','api/v1/woocommerce/*','login','ir/*','up')||$response->getStatusCode()>=400)$response->headers->set('X-Robots-Tag','noindex, nofollow');
        return $response;
    }
}
