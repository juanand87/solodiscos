@extends('layout')
@section('title','Descuentos · SoloDiscos')
@section('content')
<link rel="stylesheet" href="{{ asset('discounts.css') }}?v=2">
<div class="heading"><div><span class="eyebrow">BAJADAS DE PRECIO REGISTRADAS</span><h1>Descuentos</h1><p>Discos que hoy cuestan menos que en su primer precio válido registrado en SoloDiscos.</p></div></div>
<form class="panel discount-filters" method="get"><label>Buscar<input name="q" maxlength="150" value="{{ $filters['q']??'' }}" placeholder="Artista o álbum"></label><label>Tienda<select name="store_id"><option value="">Todas las tiendas</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(($filters['store_id']??'')==$store->id)>{{ $store->name }}</option>@endforeach</select></label><label>Formato<select name="format"><option value="">CD y vinilo</option><option value="cd" @selected(($filters['format']??'')==='cd')>CD</option><option value="vinyl" @selected(($filters['format']??'')==='vinyl')>Vinilo</option></select></label><label>Condición<select name="condition"><option value="">Todas</option>@foreach(['new'=>'Nuevo','used'=>'Usado','unknown'=>'Sin especificar'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['condition']??'')===$key)>{{ $label }}</option>@endforeach</select></label><label>Ordenar<select name="sort"><option value="percent" @selected(($filters['sort']??'percent')==='percent')>Mayor descuento</option><option value="percent_asc" @selected(($filters['sort']??'')==='percent_asc')>Menor descuento</option><option value="price" @selected(($filters['sort']??'')==='price')>Menor precio</option><option value="recent" @selected(($filters['sort']??'')==='recent')>Última comprobación</option></select></label><button class="button">Filtrar</button></form>
<p class="discount-note">{{ number_format($products->total(),0,',','.') }} publicaciones con stock y comprobación en las últimas 72 horas. Comparamos el precio actual de cada publicación con su primer precio positivo registrado en CLP, cuya fecha se muestra. No usamos precios tachados de las tiendas ni asumimos una promoción oficial. Las ediciones pueden variar.</p>
@include('purchase-notice')
<div class="discount-grid">
@forelse($products as $product)
<article class="panel discount-card">
    <a class="discount-cover" href="{{ route('album',[$product->album_id,$product->format]) }}">
        @if($product->image)<img src="{{ $product->image }}" alt="Portada de {{ $product->album_title }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="disc" aria-hidden="true"></span><span class="sr-only">Ver {{ $product->album_title }}</span>@endif
    </a>
    <div class="discount-info">
        <span class="discount-artist">{{ $product->artist }}</span>
        <h2><a href="{{ route('album',[$product->album_id,$product->format]) }}">{{ $product->album_title }}</a></h2>
        <p class="discount-format">{{ $product->format==='cd'?'CD':'Vinilo' }} · {{ ['new'=>'Nuevo','used'=>'Usado'][$product->condition]??'Condición sin especificar' }}</p>
        <div class="discount-seller">@if($product->logo_url)<img src="{{ $product->logo_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif<strong><span class="sr-only">Vendido por: </span>{{ $product->store_name }}</strong></div>
        <div class="discount-price-line"><strong aria-label="Precio actual ${{ number_format($product->price,0,',','.') }}">${{ number_format($product->price,0,',','.') }}</strong><s title="Primer precio registrado el {{ Carbon\Carbon::parse($product->initial_at)->format('d/m/Y H:i') }}"><span class="sr-only">Primer precio registrado: </span>${{ number_format($product->initial_price,0,',','.') }}</s></div>
        <small class="discount-shipping">+ envío</small>
    </div>
    <div class="discount-card-footer">
        <span class="discount-reduction" title="Bajada respecto al primer precio registrado"><span class="discount-trend" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none"><path d="m4 7 8 9 5-5 11 14m-9 0h9v-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>−{{ number_format($product->drop_percent,1,',','.') }}%</span>
        <a class="button discount-offer-button" href="{{ route('offers.visit',$product->id) }}" target="_blank" rel="noopener noreferrer">Ver oferta en tienda <span aria-hidden="true">↗</span></a>
    </div>
    <details class="discount-history"><summary>Ver gráfico e historial de precios</summary>
        <p>Ha bajado ${{ number_format($product->initial_price-$product->price,0,',','.') }} desde el primer precio registrado el {{ Carbon\Carbon::parse($product->initial_at)->format('d/m/Y H:i') }}.</p>
        @include('price-chart',['chart'=>$charts[$product->id]])
        <a href="{{ route('discounts.history',$product->id) }}">Ver historial completo →</a>
    </details>
</article>
@empty<div class="panel"><h2>No hay bajadas con estos filtros</h2><p>Se necesita un primer precio válido y un precio actual menor, de la misma publicación y moneda. Las próximas extracciones actualizarán esta sección.</p><a href="{{ route('discounts') }}">Restablecer filtros</a></div>@endforelse
</div>{{ $products->links('pagination') }}
@endsection
