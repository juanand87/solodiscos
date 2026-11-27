@extends('layout')
@section('title',$album->artist.' — '.$album->title.' · '.($format==='cd'?'CD':'Vinilo').' | SoloDiscos')
@section('content')<a class="catalog-back-button" data-catalog-return href="{{ $backUrl }}"><span aria-hidden="true">←</span> Volver al catálogo</a>
<section class="publication-heading panel">
@if($image=$album->offers->firstWhere('image','!=',null)?->image)<img class="publication-cover" src="{{ $image }}" alt="{{ $album->title }}" referrerpolicy="no-referrer">@endif
<div><span class="eyebrow">{{ $album->artist }}</span><h1>{{ $album->title }}</h1><div class="format-tabs">@foreach($formats as $otherFormat)<a class="tag {{ $format===$otherFormat?'selected-format':'' }}" href="{{ route('album',[$album,$otherFormat]+request()->only('condition','currency','comparison','return_to')) }}">{{ $otherFormat==='cd'?'CD':'Vinilo' }}</a>@endforeach</div>
<p>{{ $album->offers->unique('store_id')->count() }} {{ $album->offers->unique('store_id')->count()===1?'tienda':'tiendas' }} para comparar @if(request('condition')) · {{ ['new'=>'Nuevos','used'=>'Usados','unknown'=>'Condición sin especificar'][request('condition')] }} @endif</p>
@foreach($album->offers->groupBy('currency') as $currency=>$items)<strong class="price">Desde {{ $currency }} {{ number_format($items->min('price'),$currency==='CLP'?0:2,',','.') }}</strong>@endforeach
<p class="muted">Las ediciones pueden variar. Revisa la descripción de cada tienda.</p></div></section>
@include('purchase-notice')
<section class="panel"><div class="section-heading"><h2>Compara las alternativas</h2><span class="tag">{{ $format==='cd'?'CD':'Vinilo' }}</span></div>
@foreach($album->offers as $offer)<article class="offer-row">@if($offer->image)<img src="{{ $offer->image }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif
<div class="offer-info"><div class="seller"><span class="seller-label">Vendido por:</span>@if($offer->store->logo_url)<img class="store-logo" src="{{ $offer->store->logo_url }}" alt="Logo de {{ $offer->store->name }}" loading="lazy" referrerpolicy="no-referrer">@endif<strong>{{ $offer->store->name }}</strong></div>
<p>{{ $offer->title }}</p><span class="tag condition-tag">{{ ['new'=>'Nuevo','used'=>'Usado','unknown'=>'Condición sin especificar'][$offer->condition] ?? 'Condición sin especificar' }}</span>
<details><summary>Descripción de la tienda</summary><p class="description">{{ $offer->description ?: 'Sin descripción.' }}</p></details></div>
<div><strong class="price">{{ $offer->currency.' '.number_format($offer->price,$offer->currency==='CLP'?0:2,',','.') }}</strong>@include('initial-discount-badge')@include('price-badge')<small>+ envío</small><a class="button secondary" href="{{ route('offers.visit',$offer->id) }}" target="_blank" rel="noopener noreferrer">Ver en tienda</a></div></article>@endforeach</section>
<a class="catalog-back-button catalog-back-bottom" data-catalog-return href="{{ $backUrl }}"><span aria-hidden="true">←</span> Volver al catálogo</a>
@endsection
