@extends('portal.layout')
@section('portal-title','Mi catálogo')
@section('portal-content')
<form class="panel portal-filters" method="get"><label>Buscar disco<input name="q" maxlength="150" value="{{ $filters['q']??'' }}" placeholder="Título o artista"></label>
<label>Estado<select name="state"><option value="">Todos</option>@foreach(['published'=>'Publicados','out_of_stock'=>'Sin stock informado','pending'=>'Identificación pendiente','excluded'=>'Excluidos','unpublished'=>'Despublicados'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['state']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Formato<select name="format"><option value="">Todos</option>@foreach(['cd'=>'CD','vinyl'=>'Vinilo','unknown'=>'Sin identificar'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['format']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Condición<select name="condition"><option value="">Todas</option>@foreach(['new'=>'Nuevo','used'=>'Usado','unknown'=>'Sin especificar'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['condition']??'')===$key)>{{ $label }}</option>@endforeach</select></label><button class="button">Filtrar</button><a href="{{ route('portal.catalog') }}">Restablecer</a></form>
<p>{{ number_format($offers->total(),0,',','.') }} publicaciones. Los filtros se aplican a la clasificación actual.</p>
<div class="portal-catalog-list" role="list" aria-label="Publicaciones de mi tienda">
<div class="portal-catalog-columns" aria-hidden="true"><span>Disco</span><span>Formato / condición</span><span>Precio</span><span>Stock</span><span>Estado</span><span>Acciones</span></div>
@forelse($offers as $offer)
@php($published=$offer->isPublished())
<article class="portal-catalog-row" role="listitem">
<div class="portal-catalog-disc">@if($offer->image)<img src="{{ $offer->image }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else<span class="portal-catalog-placeholder disc" aria-hidden="true"></span>@endif<div><h2 title="{{ $offer->title }}">{{ $offer->title }}</h2>@if($offer->album?->artist)<small>{{ $offer->album->artist }}</small>@endif</div></div>
<div class="portal-catalog-format"><strong>{{ ['cd'=>'CD','vinyl'=>'Vinilo'][$offer->format]??'Sin identificar' }}</strong><small>{{ ['new'=>'Nuevo','used'=>'Usado'][$offer->condition]??'Sin especificar' }}</small></div>
<strong class="portal-catalog-price">{{ $offer->price!==null?$offer->currency.' '.number_format($offer->price,0,',','.'):'Sin precio' }}</strong>
<div class="portal-catalog-stock"><strong>Stock: {{ $offer->stock_quantity??'—' }}</strong><small>{{ ['in_stock'=>'Con stock','out_of_stock'=>'Agotado','unknown'=>'Sin confirmar'][$offer->availability]??'Sin confirmar' }}@if($offer->stock_quantity_source==='estimated_availability') · estimado @endif</small></div>
<span class="portal-catalog-status {{ $published?'is-published':'' }}">{{ $published?'Publicada':'Despublicada' }}</span>
<div class="portal-catalog-actions">@if($published)<a href="{{ route('album',[$offer->album_id,$offer->format]) }}" aria-label="Ver {{ $offer->title }} en SoloDiscos">Ver disco</a>@else<a href="{{ $offer->url }}" target="_blank" rel="noopener noreferrer" aria-label="Ver {{ $offer->title }} en la tienda">Origen ↗</a>@endif
<details><summary>Más</summary><div class="portal-catalog-more"><p><strong>{{ $offer->title }}</strong></p><p>Comprobación: {{ $offer->checked_at?->format('d/m/Y H:i')??'Sin registro' }}</p>@if($offer->excluded_reason)<p>Exclusión: {{ $offer->excluded_reason }}</p>@endif<a href="{{ $offer->url }}" target="_blank" rel="noopener noreferrer">Publicación de origen ↗</a><a href="{{ route('portal.correction',$offer) }}">Solicitar corrección</a></div></details>
</div></article>
@empty<p class="portal-catalog-empty">No hay publicaciones para estos filtros.</p>@endforelse
</div>{{ $offers->links('pagination') }}
@endsection