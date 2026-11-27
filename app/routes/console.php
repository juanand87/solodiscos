<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Store;
use App\Services\ScrapeDispatcher;
Artisan::command('prices:seed',function(){
    $count=app(\App\Services\PriceHistory::class)->seed();\App\Services\PriceHistory::invalidate();
    $this->info('Ofertas con punto de partida comprobado: '.$count.'. No se han inventado días de historial.');
})->purpose('Inicia el seguimiento diario con los precios actualmente comprobados');
Artisan::command('stats:backfill',function(){
    $this->info('Ofertas con historial revisado: '.app(\App\Services\OfferEvents::class)->backfill());
})->purpose('Recupera movimientos de estadísticas desde el historial existente sin consultar tiendas');
Artisan::command('stores:scrape {store? : ID de una tienda}',function() {
    $query=Store::where('active',true);
    if ($id=$this->argument('store')) $query->whereKey($id);
    foreach ($query->get() as $store) {
        if ($id || !$store->last_attempt_at || $store->last_attempt_at->lte(now()->subHours($store->interval_hours))) {
            $sent=app(ScrapeDispatcher::class)->dispatch($store);
            $this->line($store->name.($sent?': en cola':': ya pendiente'));
        }
    }
})->purpose('Encola las tiendas activas que requieren actualización');
Schedule::command('stores:scrape')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('covers:scan {--artist=} {--limit=100} {--queue}',function() {
    $run=app(\App\Services\CoverScanDispatcher::class)->start($this->option('artist')?:null,(int)$this->option('limit'));
    if(!$run){$this->error('Ya hay una comparación pendiente o en curso.');return 1;}
    $this->info('Recorrido en cola: '.$run->id.'. Continuará por lotes automáticos hasta terminar.');
})->purpose('Compara portadas por pHash sin IA; genera propuestas para revisión administrativa');

Artisan::command('covers:continue',function(){
    $this->info('Recorridos reanudados: '.app(\App\Services\CoverScanDispatcher::class)->recover());
})->purpose('Recupera lotes de portadas interrumpidos sin repetir pares ya procesados');
Schedule::command('covers:continue')->everyMinute()->withoutOverlapping();

Artisan::command('catalog:review-altoque {--apply : Guarda la reclasificación} {--examples=1000}',function() {
    $review=app(\App\Services\AlToqueReview::class);$normalizer=app(\App\Services\ProductNormalizer::class);
    $report=['mode'=>$this->option('apply')?'apply':'preview','resolved'=>0,'excluded'=>0,'remaining'=>0,'examples'=>[],'unresolved'=>[]];
    $apply=$this->option('apply');$limit=max(0,min(10000,(int)$this->option('examples')));
    \App\Models\Offer::whereHas('store',fn($q)=>$q->where('url','https://altoquerecords.cl'))->whereNull('excluded_reason')->where('reviewed',false)
        ->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'))->with(['album','store'])->chunkById(200,function($offers)use($review,$normalizer,$apply,$limit,&$report) {
            foreach ($offers as $offer) {
                $source=$review->enrich($offer->title,$offer->source_metadata??[],$offer->description??'');
                $parsed=$normalizer->parse($offer->title,'',$offer->store->default_condition,$source,$offer->description??'');
                $excluded=$parsed['excluded_reason']!==null;
                $resolved=!$excluded && ($offer->album_id || $parsed['artist']) && in_array($parsed['format'],['cd','vinyl']);
                $state=$excluded?'excluded':($resolved?'resolved':'remaining');$report[$state]++;
                $example=['id'=>$offer->id,'title'=>$offer->title,'previous_format'=>$offer->format,'format'=>$parsed['format'],'artist'=>$parsed['artist'],'album'=>$parsed['album'],
                    'reason'=>$parsed['excluded_reason']??$parsed['format_evidence'],'identity_evidence'=>$source['identity_evidence']??null];
                if ($state==='remaining') { if(count($report['unresolved'])<$limit)$report['unresolved'][]=$example; }
                elseif(count($report['examples'])<$limit)$report['examples'][]=$example;
                if ($apply) \Illuminate\Support\Facades\DB::transaction(function()use($offer,$source,$parsed,$normalizer) {
                    $fresh=\App\Models\Offer::lockForUpdate()->findOrFail($offer->id);
                    if ($fresh->reviewed || $fresh->title!==$offer->title) return;
                    $values=['source_metadata'=>$source,'format'=>$parsed['format'],'excluded_reason'=>$parsed['excluded_reason'],'format_evidence'=>$parsed['format_evidence']];
                    if (!$fresh->album_id && $parsed['artist'] && !$parsed['excluded_reason']) {
                        $album=\App\Models\Album::firstOrCreate(['match_key'=>$normalizer->key($parsed['artist'],$parsed['album'])],['artist'=>mb_substr($parsed['artist'],0,255),'title'=>mb_substr($parsed['album'],0,255)]);
                        $values['album_id']=$album->id;
                    }
                    $fresh->update($values);
                });
            }
        });
    $this->line(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
})->purpose('Simula o aplica la revisión local de pendientes de Al Toque sin cambiar stock, precio ni revisiones manuales');

Artisan::command('catalog:review-punto {--apply : Guarda la reclasificación} {--examples=1000}',function() {
    $review=app(\App\Services\PuntoMusicalReview::class);$normalizer=app(\App\Services\ProductNormalizer::class);
    $report=['mode'=>$this->option('apply')?'apply':'preview','resolved'=>0,'excluded'=>0,'remaining'=>0,'examples'=>[],'unresolved'=>[]];
    $apply=$this->option('apply');$limit=max(0,min(10000,(int)$this->option('examples')));
    \App\Models\Offer::whereHas('store',fn($q)=>$q->where('url','https://puntomusical.cl'))->whereNull('excluded_reason')->where('reviewed',false)
        ->where(fn($q)=>$q->whereNull('album_id')->orWhere('format','unknown'))->with(['album','store'])->chunkById(200,function($offers)use($review,$normalizer,$apply,$limit,&$report) {
            foreach ($offers as $offer) {
                $source=$review->enrich($offer->title,$offer->source_metadata??[],$offer->description??'');
                $parsed=$normalizer->parse($offer->title,'',$offer->store->default_condition,$source,$offer->description??'');
                $excluded=$parsed['excluded_reason']!==null;
                $resolved=!$excluded && ($offer->album_id || $parsed['artist']) && in_array($parsed['format'],['cd','vinyl']);
                $state=$excluded?'excluded':($resolved?'resolved':'remaining');$report[$state]++;
                $example=['id'=>$offer->id,'title'=>$offer->title,'previous_format'=>$offer->format,'format'=>$parsed['format'],'artist'=>$parsed['artist'],'album'=>$parsed['album'],
                    'reason'=>$parsed['excluded_reason']??$parsed['format_evidence'],'identity_evidence'=>$source['identity_evidence']??null];
                if ($state==='remaining') { if(count($report['unresolved'])<$limit)$report['unresolved'][]=$example; }
                elseif (count($report['examples'])<$limit) $report['examples'][]=$example;
                if ($apply) {
                    \Illuminate\Support\Facades\DB::transaction(function()use($offer,$source,$parsed,$normalizer) {
                        $fresh=\App\Models\Offer::lockForUpdate()->findOrFail($offer->id);
                        if ($fresh->reviewed || $fresh->title!==$offer->title) return;
                        $values=['source_metadata'=>$source,'format'=>$parsed['format'],'excluded_reason'=>$parsed['excluded_reason'],'format_evidence'=>$parsed['format_evidence']];
                        if (!$fresh->album_id && $parsed['artist'] && !$parsed['excluded_reason']) {
                            $album=\App\Models\Album::firstOrCreate(['match_key'=>$normalizer->key($parsed['artist'],$parsed['album'])],['artist'=>mb_substr($parsed['artist'],0,255),'title'=>mb_substr($parsed['album'],0,255)]);
                            $values['album_id']=$album->id;
                        }
                        $fresh->update($values);
                    });
                }
            }
        });
    $this->line(json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
})->purpose('Simula o aplica la revisión de pendientes de Punto Musical sin consultar proveedores ni cambiar stock');

Artisan::command('catalog:enrich-billboard {--apply : Guarda asociaciones verificadas} {--limit=300} {--offer=} {--include-out-of-stock}',function() {
    $lock=\Illuminate\Support\Facades\Cache::lock('billboard-barcode-enrichment',7200);
    if (!$lock->get()) { $this->error('Ya hay una revisión de códigos en curso.');return 1; }
    try {
        $resolver=app(\App\Services\BillboardBarcodeResolver::class);$client=app(\App\Services\StoreClient::class);
        $normalizer=app(\App\Services\ProductNormalizer::class);$apply=$this->option('apply');
        $query=\App\Models\Offer::whereHas('store',fn($q)=>$q->where('url','https://www.billboard.cl'))
            ->whereNull('album_id')->whereNull('excluded_reason')->where('reviewed',false)->whereIn('format',['cd','vinyl']);
        if (!$this->option('include-out-of-stock')) $query->where('availability','in_stock');
        if ($this->option('offer')) $query->whereKey((int)$this->option('offer'));
        $offers=$query->orderBy('id')->limit(max(1,min(1000,(int)$this->option('limit'))))->get();
        $resolved=0;$errors=0;$processed=0;
        foreach ($offers as $offer) {
            try {
                $cache=storage_path('app/private/billboard-codes/'.$offer->id.'.json');
                $cached=is_file($cache)?json_decode(file_get_contents($cache),true):null;
                if ($cached && ($cached['url']??'')===$offer->url) $code=$cached['code'];
                else {
                    $html=$client->html('https://www.billboard.cl',parse_url($offer->url,PHP_URL_PATH));
                    $code=$resolver->detailCode($html,$offer->url);
                    if (!is_dir(dirname($cache))) mkdir(dirname($cache),0775,true);
                    file_put_contents($cache,json_encode(['url'=>$offer->url,'code'=>$code]),LOCK_EX);
                    usleep(1100000);
                }
                $identity=$code?$resolver->select($resolver->lookup($code),$code,$offer->title,$offer->format):null;
                if ($identity) {
                    $resolved++;
                    if ($apply) {
                        $album=\App\Models\Album::firstOrCreate(['match_key'=>$normalizer->key($identity['artist'],$identity['album'])],['artist'=>mb_substr($identity['artist'],0,255),'title'=>mb_substr($identity['album'],0,255)]);
                        // A manual review or importer may have updated the row while fetching.
                        \Illuminate\Support\Facades\DB::transaction(function()use($offer,$album,$identity,$code) {
                            $fresh=\App\Models\Offer::lockForUpdate()->findOrFail($offer->id);
                            if ($fresh->reviewed || $fresh->album_id || $fresh->title!==$offer->title) return;
                            $source=$fresh->source_metadata??[];$source['barcode']=$code;$source['verified_identity']=$identity;
                            $source['identity_evidence']=$identity['evidence'];$source['identity_pending']=false;
                            $source['identifiers']=app(\App\Services\ProductIdentifiers::class)->extract($source,$fresh->description??'');
                            $fresh->update(['album_id'=>$album->id,'source_metadata'=>$source]);
                        });
                    }
                    $this->line('IDENTIFIED '.$offer->id.' '.json_encode($identity,JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) { $errors++;$this->line('ERROR '.$offer->id.' '.mb_substr($e->getMessage(),0,200)); }
            $processed++;
            if ($processed%5===0) $this->line("PROGRESS $processed/".$offers->count()." identified=$resolved errors=$errors");
            if ($errors>=10) { $this->error('Revisión detenida tras diez errores de consulta; el catálogo se conserva.');break; }
        }
        $this->line("RESULT processed=$processed identified=$resolved errors=$errors");
    } finally { $lock->release(); }
})->purpose('Contrasta GTIN de fichas Billboard con MusicBrainz; conserva stock, precio y verificaciones previas');

Artisan::command('catalog:identify-billboard {--apply : Guarda las asociaciones; sin esta opción solo simula} {--examples=20 : Cantidad máxima de ejemplos del informe}',function() {
    $resolver=app(\App\Services\BillboardIdentityResolver::class);
    $normalizer=app(\App\Services\ProductNormalizer::class);
    $resolved=0;$remaining=0;$examples=[];$unresolved=[];$apply=$this->option('apply');$limit=max(0,min(10000,(int)$this->option('examples')));
    \App\Models\Offer::whereHas('store',fn($q)=>$q->where('url','https://www.billboard.cl'))
        ->whereNull('album_id')->whereNull('excluded_reason')->where('reviewed',false)
        ->chunkById(200,function($offers)use($resolver,$normalizer,&$resolved,&$remaining,&$examples,&$unresolved,$apply,$limit) {
            foreach ($offers as $offer) {
                $identity=$resolver->resolve($offer->title);
                if (!$identity) { $remaining++;if(count($unresolved)<$limit)$unresolved[]=['id'=>$offer->id,'title'=>$offer->title];continue; }
                $resolved++;
                if (count($examples)<$limit) $examples[]=['id'=>$offer->id,'original'=>$offer->title]+$identity;
                if ($apply) {
                    $album=\App\Models\Album::firstOrCreate(['match_key'=>$normalizer->key($identity['artist'],$identity['album'])],
                        ['artist'=>mb_substr($identity['artist'],0,255),'title'=>mb_substr($identity['album'],0,255)]);
                    $source=$offer->source_metadata??[];
                    $source['artist']=$identity['artist'];$source['album']=$identity['album'];
                    $source['identity_pending']=false;$source['identity_evidence']=$identity['evidence'];
                    // Reclassification never updates stock, price or last checked time.
                    $offer->update(['album_id'=>$album->id,'source_metadata'=>$source]);
                }
            }
        });
    $this->line(json_encode(['mode'=>$apply?'apply':'preview','resolved'=>$resolved,'remaining'=>$remaining,'examples'=>$examples,'unresolved'=>$unresolved],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
})->purpose('Identifica títulos pendientes de Billboard con artistas de otras tiendas, sin consultar proveedores');

Artisan::command('stores:logos {store?}',function() {
    $query=Store::query(); if ($id=$this->argument('store')) $query->whereKey($id);
    foreach($query->get() as $store) {
        try { $logo=app(\App\Services\StoreBranding::class)->discover($store); $this->line($store->name.': '.($logo??'No se encontró logo')); }
        catch(\Throwable $e) { $this->error($store->name.': '.$e->getMessage()); }
    }
})->purpose('Extrae el logo público de las tiendas');

Artisan::command('catalog:regroup',function() {
    $normalizer=app(\App\Services\ProductNormalizer::class); $changed=0;
    \App\Models\Offer::chunkById(200,function($offers) {
        foreach ($offers as $offer) {
            $source=$offer->source_metadata??[];
            $source['identifiers']=app(\App\Services\ProductIdentifiers::class)->extract($source,$offer->description??'');
            if ($source!==$offer->source_metadata) $offer->update(['source_metadata'=>$source]);
        }
    });
    \App\Models\Offer::with('album')->whereNotNull('album_id')->where('reviewed',false)->chunkById(200,function($offers)use($normalizer,&$changed) {
        foreach($offers as $offer) {
            $decoded=html_entity_decode($offer->title,ENT_QUOTES|ENT_HTML5,'UTF-8');
            if ($decoded!==$offer->title) $offer->update(['title'=>$decoded]);
            $title=$normalizer->albumTitle($offer->album->title);
            $album=\App\Models\Album::firstOrCreate(['match_key'=>$normalizer->key($offer->album->artist,$title)],['artist'=>$offer->album->artist,'title'=>$title]);
            if ($album->id!==$offer->album_id) { $offer->update(['album_id'=>$album->id]);$changed++; }
        }
    });
    $this->info("Ofertas reagrupadas: $changed. Las clasificaciones manuales se conservaron.");
})->purpose('Reagrupa coincidencias automáticas por artista y título normalizado');

Artisan::command('catalog:clean-descriptions',function() {
    $formatter=app(\App\Services\ProductDescription::class); $changed=0;
    \App\Models\Offer::whereNotNull('description')->chunkById(200,function($offers)use($formatter,&$changed) {
        foreach($offers as $offer) {
            $clean=$formatter->fromText($offer->description);
            if ($clean!==$offer->description) { $offer->update(['description'=>$clean]); $changed++; }
        }
    });
    $this->info("Descripciones compactadas: $changed.");
})->purpose('Limpia espacios y líneas vacías de las descripciones existentes sin consultar las tiendas');

Artisan::command('catalog:audit-products',function() {
    $classifier=app(\App\Services\ProductClassifier::class);$excluded=0;
    \App\Models\Offer::chunkById(200,function($offers)use($classifier,&$excluded) {
        foreach($offers as $offer) {
            $result=$classifier->classify($offer->title,'',$offer->description??'',$offer->source_metadata??[]);
            // Historical offers lack source metadata: only act on positive exclusion evidence.
            if ($result['excluded_reason']) {
                if (!$offer->excluded_reason) $excluded++;
                $offer->update(['excluded_reason'=>$result['excluded_reason'],'format'=>'unknown','format_evidence'=>null]);
            }
        }
    });
    $this->info("Productos no musicales excluidos: $excluded. Conservados en administración, sin borrar historial.");
})->purpose('Despublica libros y accesorios con evidencia explícita en los datos ya guardados');
