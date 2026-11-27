<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StoreIntegrationController;
Route::middleware([\App\Http\Middleware\StoreIntegrationAccess::class,'throttle:store-integration'])->prefix('v1/woocommerce')->group(function(){
    Route::post('/orders',[StoreIntegrationController::class,'orders']);
    Route::get('/summary',[StoreIntegrationController::class,'summary']);
    Route::get('/dashboard',[StoreIntegrationController::class,'dashboard']);
    Route::get('/attributed-orders',[StoreIntegrationController::class,'attributedOrders']);
});
