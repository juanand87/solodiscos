<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionHttps {
    public function handle(Request $request,Closure $next): Response {
        $appUrl=rtrim((string)config('app.url'),'/');
        $public=$request->isMethod('GET') && ($request->path()==='/' || $request->is('album/*','rankings','rankings/*','descuentos','descuentos/*','politicas/*','sitemap*.xml'));
        // Redirect before sessions and CSRF: secure cookies cannot survive an HTTP login.
        if(app()->environment('production') && parse_url($appUrl,PHP_URL_SCHEME)==='https' && !$request->isSecure())
            return redirect()->to($appUrl.$request->getRequestUri(),$public?301:307);

        if($public && app()->environment('production') && parse_url($appUrl,PHP_URL_HOST) && $request->getHost()!==parse_url($appUrl,PHP_URL_HOST))
            return redirect()->to($appUrl.$request->getRequestUri(),301);

        return $next($request);
    }
}
