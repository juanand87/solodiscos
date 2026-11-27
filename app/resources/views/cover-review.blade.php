@extends('layout')
@section('content')
<link rel="stylesheet" href="{{ asset('cover-review.css') }}">
<div class="heading"><div><span class="eyebrow">REVISIÓN VISUAL · SIN IA</span><h1>Compara las portadas.</h1><p>Propuestas entre el mismo artista y formato. Tú decides qué ofertas pertenecen al mismo álbum.</p></div><span class="tag">{{ $pending }} propuestas pendientes</span></div>
<section class="panel"><h2>Buscar posibles duplicados</h2><form class="cover-scan-form" method="post" action="{{ route('covers.scan') }}">@csrf
<label>Artista (opcional)<input name="artist" maxlength="100" placeholder="Por ejemplo, Adele" value="{{ request('artist') }}"></label>
<label>Imágenes nuevas por lote<select name="limit">@foreach([25,50,100,250,500] as $limit)<option value="{{ $limit }}" @selected($limit===25)>{{ $limit }}</option>@endforeach</select></label><button class="button">Comparar portadas</button></form>
<p class="muted">Deja el artista vacío para recorrer todo el catálogo identificado, incluidos agotados. Los lotes continúan automáticamente hasta terminar y reutilizan las huellas guardadas durante siete días. Se comparan el mismo artista y formato, con títulos compatibles; números distintos como 25 y 30 se mantienen separados. Todas las coincidencias requieren revisión: no se agrupan publicaciones automáticamente.</p>
<div id="cover-progress" data-url="{{ route('covers.progress') }}" data-running="{{ $scan && in_array($scan->status,['queued','running'])?'1':'0' }}" role="status" aria-live="polite">
@if($scan)<strong>Última comparación: {{ ['queued'=>'En cola','running'=>'En ejecución','success'=>'Completada','failed'=>'Interrumpida'][$scan->status]??$scan->status }}</strong><p>{{ $scan->processed }} / {{ $scan->total_images }} imágenes revisadas · {{ $scan->compared_pairs + $scan->skipped_pairs }} / {{ $scan->total_pairs }} pares resueltos · {{ $scan->batch_count }} lotes completados · {{ $scan->suggestions }} propuestas nuevas · {{ $scan->errors }} imágenes no utilizables.</p><small>{{ $scan->message }}</small>@else<p>Todavía no se han comparado portadas.</p>@endif</div>
<a id="cover-refresh" class="button secondary" href="{{ request()->fullUrl() }}" hidden>Actualizar propuestas</a></section>
<form class="search" method="get"><input name="artist" placeholder="Filtrar por artista" value="{{ request('artist') }}"><select name="status" aria-label="Estado">@foreach(['pending'=>'Pendientes','accepted'=>'Aprobadas','rejected'=>'Descartadas','reverted'=>'Deshechas','obsolete'=>'Datos cambiados'] as $value=>$label)<option value="{{ $value }}" @selected(request('status','pending')===$value)>{{ $label }}</option>@endforeach</select><label>Resultados por página<select name="per_page">@foreach([100,200,300,400,500] as $size)<option value="{{ $size }}" @selected((int)request('per_page',100)===$size)>{{ $size }}</option>@endforeach</select></label><button class="button secondary">Filtrar</button></form>
@php($pageIds=$matches->filter(fn($m)=>$m->status==='pending' && $m->leftOffer && $m->rightOffer && $m->leftOffer->album_id===$m->left_album_id && $m->rightOffer->album_id===$m->right_album_id && $m->leftOffer->image===$m->leftCover->url && $m->rightOffer->image===$m->rightCover->url && $m->leftOffer->format===$m->format && $m->rightOffer->format===$m->format)->pluck('id')->values()->all())
@if(count($pageIds))
<form id="cover-bulk" method="post" action="{{ route('covers.accept-page') }}" class="panel">@csrf
<input type="hidden" name="page_token" value="{{ Illuminate\Support\Facades\Crypt::encryptString(json_encode(['ids'=>$pageIds,'artist'=>request('artist'),'per_page'=>$matches->perPage()])) }}">
<p>Revisa las comparaciones de esta página. Se conserva el primer álbum (izquierda) por defecto; puedes elegir el de la derecha en cada propuesta. Las propuestas desactualizadas o protegidas se omiten.</p>
<button class="button" name="confirm" value="1">Aprobar todas las de esta página ({{ count($pageIds) }})</button></form>
@endif
@forelse($matches as $match)
@php($left=$match->leftOffer) @php($right=$match->rightOffer)
@php($stale=!$left || !$right || $left->album_id!==$match->left_album_id || $right->album_id!==$match->right_album_id || $left->image!==$match->leftCover->url || $right->image!==$match->rightCover->url || $left->format!==$match->format || $right->format!==$match->format)
<article class="panel cover-match"><div class="section-heading"><h2>{{ $left?->album?->artist ?? 'Artista' }} · {{ $match->format==='cd'?'CD':'Vinilo' }}</h2><span class="tag">Distancia pHash: {{ $match->distance }} / 63</span></div>
<p class="muted">Una distancia menor indica mayor parecido visual, no una probabilidad de que sea el mismo producto. Comprueba título, descripción y formato.</p>
<div class="cover-comparison">@foreach([['offer'=>$left,'cover'=>$match->leftCover,'side'=>'left'],['offer'=>$right,'cover'=>$match->rightCover,'side'=>'right']] as $side)
<section><img class="review-cover" src="{{ route('covers.thumbnail',$side['cover']) }}" alt="Portada de {{ $side['offer']?->title }}" loading="lazy">
<p><span class="tag">Formato registrado: <strong>{{ ['cd'=>'CD','vinyl'=>'Vinilo'][$side['offer']?->format] ?? 'Sin identificar' }}</strong></span></p>
<h3>{{ $side['offer']?->album?->title }}</h3><p><strong>{{ $side['offer']?->store?->name }}</strong><br>{{ $side['offer']?->title }}</p>
@if($side['offer'])<p><a href="{{ route('offers.edit',$side['offer']) }}">Revisar datos y descripción</a> · <a href="{{ $side['offer']->url }}" target="_blank" rel="noopener noreferrer">Ver en tienda</a></p>@if($side['offer']->reviewed)<small class="muted">Clasificación manual protegida</small>@endif @endif</section>@endforeach</div>
@if($match->status==='pending')
@if($stale)<p class="errors">Los datos de esta propuesta cambiaron. Descártala y ejecuta otra comparación.</p>@endif
<input type="hidden" form="cover-bulk" name="targets[{{ $match->id }}]" value="left" id="bulk-target-{{ $match->id }}" @disabled($stale)>
<form method="post" action="{{ route('covers.accept',$match) }}" class="cover-decision" data-match="{{ $match->id }}">@csrf
<fieldset @disabled($stale)><legend>Nombre que se conservará al agrupar</legend><label class="check"><input type="radio" name="target" value="left" required checked> {{ $left?->album?->title }} (izquierda)</label><label class="check"><input type="radio" name="target" value="right" required> {{ $right?->album?->title }} (derecha)</label>
<p class="muted">Se moverán las ofertas automáticas del álbum de origen en este formato. Las clasificaciones manuales anteriores se conservan; los títulos de cada tienda no cambian.</p><button class="button">Confirmar agrupación</button></fieldset></form>
<form method="post" action="{{ route('covers.reject',$match) }}">@csrf<button class="button secondary">Descartar propuesta</button></form>
@elseif($match->status==='accepted')<p class="notice">Agrupación aprobada · {{ count($match->changes??[]) }} ofertas.</p><form method="post" action="{{ route('covers.undo',$match) }}">@csrf<button class="button secondary">Deshacer agrupación</button></form>
@else<p class="muted">{{ ['rejected'=>'Propuesta descartada','reverted'=>'Agrupación deshecha','obsolete'=>'Propuesta con datos cambiados'][$match->status]??$match->status }}</p>@endif
</article>@empty<section class="panel"><h2>No hay propuestas con estos filtros.</h2><p>Ejecuta una comparación o consulta otro estado. Si ya agrupaste álbumes, las portadas iguales del mismo álbum no se proponen de nuevo.</p></section>@endforelse
@if(count($pageIds))<p><button class="button" form="cover-bulk" name="confirm" value="1">Aprobar todas las de esta página ({{ count($pageIds) }})</button></p>@endif
{{ $matches->links('pagination') }}
<script src="{{ asset('cover-review.js') }}" defer></script>
@endsection
