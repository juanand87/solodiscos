@extends('portal.layout')
@section('portal-title','Mi catálogo')
@section('portal-content')
<form class="panel portal-filters" method="get"><label>Buscar disco<input name="q" maxlength="150" value="{{ $filters['q']??'' }}" placeholder="Título o artista"></label>
<label>Estado<select name="state"><option value="">Todos</option>@foreach(['published'=>'Publicados','out_of_stock'=>'Sin stock informado','pending'=>'Identificación pendiente','excluded'=>'Excluidos','unpublished'=>'Despublicados'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['state']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Formato<select name="format"><option value="">Todos</option>@foreach(['cd'=>'CD','vinyl'=>'Vinilo','unknown'=>'Sin identificar'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['format']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Condición<select name="condition"><option value="">Todas</option>@foreach(['new'=>'Nuevo','used'=>'Usado','unknown'=>'Sin especificar'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['condition']??'')===$key)>{{ $label }}</option>@endforeach</select></label><button class="button">Filtrar</button><a href="{{ route('portal.catalog') }}">Restablecer</a></form>
<p>{{ number_format($offers->total(),0,',','.') }} publicaciones. Los filtros se aplican a la clasificación actual.</p>
<div class="portal-products">@forelse($offers as $offer)
<article class="panel portal-product">@if($offer->image)<img src="{{ $offer->image }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif<div><h2>{{ $offer->title }}</h2><p>{{ $offer->album?->artist }} · {{ ['cd'=>'CD','vinyl'=>'Vinilo'][$offer->format]??'Formato sin identificar' }} · {{ ['new'=>'Nuevo','used'=>'Usado'][$offer->condition]??'Condición sin especificar' }}</p><p><span class="tag">{{ $offer->isPublished()?'Publicada':'Despublicada' }}</span> · Stock: {{ $offer->stock_quantity??'Sin cantidad' }} @if($offer->stock_quantity_source==='estimated_availability') (estimado) @endif · {{ ['in_stock'=>'Con stock','out_of_stock'=>'Agotado','unknown'=>'Stock sin confirmar'][$offer->availability]??'Stock sin confirmar' }}</p>
@if($offer->excluded_reason)<p>Exclusión: {{ $offer->excluded_reason }}</p>@endif
<p>{{ $offer->price!==null?$offer->currency.' '.number_format($offer->price,0,',','.'):'Sin precio válido' }} · Comprobación: {{ $offer->checked_at?->format('d/m/Y H:i')??'Sin registro' }}</p>
<div class="portal-actions"><a href="{{ $offer->url }}" target="_blank" rel="noopener noreferrer">Publicación de origen ↗</a>@if($offer->isPublished())<a href="{{ route('album',[$offer->album_id,$offer->format]) }}">Ver en SoloDiscos</a>@endif<a class="button secondary" href="{{ route('portal.correction',$offer) }}">Solicitar corrección</a></div></div></article>
@empty<p class="panel">No hay publicaciones para estos filtros.</p>@endforelse</div>{{ $offers->links('pagination') }}
@endsection
