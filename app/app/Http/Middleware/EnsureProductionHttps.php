<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionHttps {
    public function handle(Request $request,Closure $next): Response {
        $appUrl=rtrim((string)config('app.url'),'/');
        // Redirect before sessions and CSRF: secure cookies cannot survive an HTTP login.
        if(app()->environment('production') && parse_url($appUrl,PHP_URL_SCHEME)==='https' && !$request->isSecure())
            return redirect()->to($appUrl.$request->getRequestUri(),307);

        return $next($request);
    }
}
