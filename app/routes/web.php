<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{AdminController,CatalogController,CoverController};
Route::get('/',[CatalogController::class,'index'])->name('catalog');
Route::get('/rankings',[\App\Http\Controllers\RankingController::class,'index'])->name('rankings');
Route::get('/rankings/estadisticas',[\App\Http\Controllers\RankingController::class,'index'])->name('rankings.statistics');
Route::get('/rankings/{store}/comparados/{condition}',[\App\Http\Controllers\RankingController::class,'sample'])->where('condition','new|used|all')->name('rankings.sample');
Route::get('/album/{album}/{format?}',[CatalogController::class,'show'])->where('format','cd|vinyl')->name('album');
Route::get('/login',[AdminController::class,'login'])->name('login')->middleware('guest');
Route::post('/login',[AdminController::class,'authenticate'])->middleware('guest');
Route::middleware('auth')->prefix('admin')->group(function() {
    Route::get('/',[AdminController::class,'index'])->name('admin');
    Route::get('/rankings',[\App\Http\Controllers\RankingController::class,'index'])->name('admin.rankings');
    Route::get('/rankings/estadisticas',[\App\Http\Controllers\RankingController::class,'index'])->name('admin.rankings.statistics');
    Route::get('/rankings/{store}/comparados/{condition}',[\App\Http\Controllers\RankingController::class,'sample'])->where('condition','new|used|all')->name('admin.rankings.sample');
    Route::get('/estadisticas',[\App\Http\Controllers\StatisticsController::class,'index'])->name('statistics.index');
    Route::get('/estadisticas/exportar',[\App\Http\Controllers\StatisticsController::class,'export'])->name('statistics.export');
    Route::get('/progreso',[AdminController::class,'progress'])->name('admin.progress');
    Route::get('/portadas',[CoverController::class,'index'])->name('covers.index');
    Route::post('/portadas/comparar',[CoverController::class,'scan'])->name('covers.scan');
    Route::post('/portadas/aprobar-pagina',[CoverController::class,'acceptPage'])->name('covers.accept-page');
    Route::get('/portadas/progreso',[CoverController::class,'progress'])->name('covers.progress');
    Route::get('/portadas/imagenes/{cover}',[CoverController::class,'thumbnail'])->name('covers.thumbnail');
    Route::post('/portadas/{match}/aprobar',[CoverController::class,'accept'])->name('covers.accept');
    Route::post('/portadas/{match}/descartar',[CoverController::class,'reject'])->name('covers.reject');
    Route::post('/portadas/{match}/deshacer',[CoverController::class,'undo'])->name('covers.undo');
    Route::post('/logout',[AdminController::class,'logout'])->name('logout');
    Route::get('/tiendas/nueva',[AdminController::class,'form'])->name('stores.create');
    Route::post('/tiendas',[AdminController::class,'save'])->name('stores.store');
    Route::get('/tiendas/{store}/editar',[AdminController::class,'form'])->name('stores.edit');
    Route::put('/tiendas/{store}',[AdminController::class,'save'])->name('stores.update');
    Route::post('/tiendas/{store}/extraer',[AdminController::class,'scrape'])->name('stores.scrape');
    Route::post('/tiendas/{store}/logo',[AdminController::class,'refreshLogo'])->name('stores.logo');
    Route::get('/ofertas',[AdminController::class,'offers'])->name('offers');
    Route::get('/ofertas/{offer}/editar',[AdminController::class,'editOffer'])->name('offers.edit');
    Route::put('/ofertas/{offer}',[AdminController::class,'saveOffer'])->name('offers.update');
});
