@extends('layout')
@section('title',$album->artist.' — '.$album->title.' · '.($format==='cd'?'CD':'Vinilo').' | SoloDiscos')
@section('content')<a class="catalog-back-button" data-catalog-return href="{{ $backUrl }}"><span aria-hidden="true">←</span> Volver al catálogo</a>
<nav class="discovery-breadcrumb" aria-label="Ruta de navegación"><a href="{{ route('catalog') }}">Inicio</a><span aria-hidden="true">›</span><a href="{{ url($artistPath) }}">{{ $album->artist }}</a><span aria-hidden="true">›</span><span>{{ $album->title }} · {{ $format==='cd'?'CD':'Vinilo' }}</span></nav>
<section class="publication-heading panel">
@if($image=$album->offers->firstWhere('image','!=',null)?->image)<img class="publication-cover" src="{{ $image }}" alt="{{ $album->title }}" referrerpolicy="no-referrer">@endif
<div><span class="eyebrow">{{ $album->artist }}</span><h1>{{ $album->title }}</h1><div class="format-tabs">@foreach($formats as $otherFormat)<a class="tag {{ $format===$otherFormat?'selected-format':'' }}" href="{{ route('album',[$album,$otherFormat]+request()->only('condition','currency','comparison','return_to')) }}">{{ $otherFormat==='cd'?'CD':'Vinilo' }}</a>@endforeach</div>
<p>{{ $album->offers->unique('store_id')->count() }} {{ $album->offers->unique('store_id')->count()===1?'tienda':'tiendas' }} para comparar @if(request('condition')) · {{ ['new'=>'Nuevos','used'=>'Usados','unknown'=>'Condición sin especificar'][request('condition')] }} @endif</p>
@foreach($album->offers->groupBy('currency') as $currency=>$items)<strong class="price">Desde {{ $currency }} {{ number_format($items->min('price'),$currency==='CLP'?0:2,',','.') }}</strong>@endforeach
<p class="muted">Las ediciones pueden variar. Revisa la descripción de cada tienda.</p></div></section>
@include('purchase-notice')
<nav class="album-discovery-links" aria-label="Explorar más discos"><a href="{{ url($artistPath) }}">Más discos de {{ $album->artist }} →</a></nav>
<section class="panel"><div class="section-heading"><h2>Compara las alternativas</h2><span class="tag">{{ $format==='cd'?'CD':'Vinilo' }}</span></div>
@php($minimumPrices=$album->offers->groupBy('currency')->map(fn($items)=>$items->min('price')))
@foreach($album->offers as $offer)@php($bestPrice=$offer->price==$minimumPrices[$offer->currency])<article @class(['offer-row','album-offer','offer-best-price'=>$bestPrice])>@if($offer->image)<img src="{{ $offer->image }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif
<div class="offer-info"><div class="seller"><span class="seller-label">Vendido por:</span>@if($offer->store->logo_url)<img class="store-logo" src="{{ $offer->store->logo_url }}" alt="Logo de {{ $offer->store->name }}" loading="lazy" referrerpolicy="no-referrer">@endif<strong><a href="{{ url(app(\App\Services\CatalogDiscovery::class)->storePath($offer->store)) }}">{{ $offer->store->name }}</a></strong></div>
@if($bestPrice)<span class="offer-best-label"><span aria-hidden="true">★</span> Mejor precio</span>@endif
<p class="offer-title">{{ $offer->title }}</p><span class="tag condition-tag">{{ ['new'=>'Nuevo','used'=>'Usado','unknown'=>'Condición sin especificar'][$offer->condition] ?? 'Condición sin especificar' }}</span>
<details><summary>Descripción de la tienda</summary><p class="offer-original-title" hidden><strong>Título en la tienda:</strong> {{ $offer->title }}</p><p class="description">{{ $offer->description ?: 'Sin descripción.' }}</p></details></div>
<div class="offer-buy"><strong class="price"><span class="offer-currency">{{ $offer->currency }}</span> {{ number_format($offer->price,$offer->currency==='CLP'?0:2,',','.') }}</strong>@include('initial-discount-badge')@include('price-badge')<small>+ envío</small><a class="button secondary" href="{{ route('offers.visit',$offer->id) }}" target="_blank" rel="noopener noreferrer">Ver en tienda</a></div></article>@endforeach</section>
<a class="catalog-back-button catalog-back-bottom" data-catalog-return href="{{ $backUrl }}"><span aria-hidden="true">←</span> Volver al catálogo</a>
@if($relatedAlbums->isNotEmpty())<section class="related-albums"><h2>Más de {{ $album->artist }} en {{ $format==='cd'?'CD':'vinilo' }}</h2><div class="album-grid">@foreach($relatedAlbums as $publication)@include('storefront.album-card')@endforeach</div><p><a href="{{ url($artistPath) }}">Ver todos sus discos →</a></p></section>@endif
@endsection
