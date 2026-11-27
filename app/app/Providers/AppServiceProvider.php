<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named limits isolate each portal action instead of sharing the generic IP counter.
        foreach(['portal-register'=>[5,60],'portal-password-email'=>[3,1],'portal-password-reset'=>[5,1],
            'portal-resend'=>[2,1],'portal-verify'=>[6,1],'portal-logout'=>[10,1],'portal-correction'=>[10,1],'portal-integration-key'=>[5,1]] as $name=>[$attempts,$minutes]){
            \Illuminate\Support\Facades\RateLimiter::for($name,fn(\Illuminate\Http\Request $request)=>
                \Illuminate\Cache\RateLimiting\Limit::perMinutes($minutes,$attempts)
                    ->by($request->user('store')?'account:'.$request->user('store')->id:'ip:'.$request->ip()));
        }
        \Illuminate\Support\Facades\RateLimiter::for('store-integration',fn(\Illuminate\Http\Request $request)=>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->bearerToken() ? 'token:'.hash('sha256',$request->bearerToken()) : 'ip:'.$request->ip()));
    }
}
