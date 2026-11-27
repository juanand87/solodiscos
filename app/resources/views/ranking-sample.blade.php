@extends('layout')
@section('title','Álbumes comparados de '.$store->name.' · SoloDiscos')
@section('content')
@php($onlyCheapest=!empty($filters['cheapest']))
<link rel="stylesheet" href="{{ asset('rankings.css') }}">
<link rel="stylesheet" href="{{ asset('comparison-cards.css') }}">
<a class="back" href="{{ route($administration?'admin.rankings':'rankings',array_diff_key($filters,['cheapest'=>true])) }}#precios">Volver al ranking</a>
<div class="heading"><div><span class="eyebrow">LOS DISCOS DETRÁS DEL RANKING</span><h1>{{ $onlyCheapest?'Productos con precio mínimo de ':'Álbumes comparados de ' }}{{ $store->name }}</h1><p><strong>{{ number_format($products->total(),0,',','.') }}</strong> álbumes y formatos · {{ $filters['condition']==='new'?'Nuevos':($filters['condition']==='used'?'Usados':'Nuevos y usados') }} · {{ [''=>'CD y vinilo','cd'=>'CD','vinyl'=>'Vinilo'][$filters['format']] }}</p></div></div>
<p class="muted">Hasta tres tiendas por disco, con una oferta por tienda. Incluye empates en el mejor precio. Comparamos el mismo álbum, formato y condición; las ediciones pueden variar.</p>
@include('purchase-notice')
<div class="comparison-list">
@forelse($products as $item)
@php($album=$item['anchor'])
@php($minimum=(float)$item['offers'][0]->price)
@php($saving=max(array_map(fn($offer)=>(float)$offer->price,$item['offers']))-$minimum)
<article class="panel sample-album comparison-card" data-album-id="{{ $album->album_id }}" data-format="{{ $album->format }}">
<div class="comparison-heading">
<a class="comparison-cover {{ $album->format==='vinyl'?'comparison-vinyl':'comparison-cd' }}" href="{{ route('album',[$album->album_id,$album->format]) }}">@if($album->image)<img src="{{ $album->image }}" alt="Portada de {{ $album->album_title }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="disc" aria-label="Sin portada"></span>@endif</a>
<div class="comparison-summary"><span class="comparison-artist">{{ $album->artist }}</span><h2><a href="{{ route('album',[$album->album_id,$album->format]) }}">{{ $album->album_title }}</a></h2><p class="comparison-meta"><span class="comparison-format">{{ $album->format==='cd'?'CD':'Vinilo' }}</span><span class="comparison-condition">{{ $album->condition==='new'?'Nuevo':'Usado' }}</span><span>{{ $item['store_count'] }} tiendas</span></p>
@if($saving>0)<p class="comparison-saving" title="Diferencia entre el menor y el mayor precio de las alternativas mostradas.">Ahorra hasta ${{ number_format($saving,0,',','.') }}</p>@endif
<p class="comparison-from">Desde <strong>${{ number_format($minimum,0,',','.') }}</strong></p>
@if(!$onlyCheapest && (float)$album->price>$minimum)<small>{{ $store->name }}: ${{ number_format($album->price,0,',','.') }}</small>@endif
</div></div>
<div class="comparison-options">
<h3 class="comparison-options-label"><span class="comparison-cart" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3h2l2.5 12h11L21 7H6"/><circle cx="9" cy="20" r="1"/><circle cx="18" cy="20" r="1"/><path d="M10 9v3m5-3v3"/></svg></span>Opciones de compra</h3>
<div class="comparison-stores">
@foreach($item['offers'] as $offer)
@php($best=$offer->price_rank===1)
<section class="comparison-store {{ $best?'comparison-best':'' }}" data-store-id="{{ $offer->store_id }}">
<div class="comparison-seller"><span class="comparison-store-logo">@if($offer->logo_url)<img src="{{ $offer->logo_url }}" alt="Logo de {{ $offer->store_name }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="disc" aria-hidden="true"></span>@endif</span><div><strong>{{ $offer->store_name }}</strong><small>{{ $best?'Mejor precio':'+ $'.number_format((float)$offer->price-$minimum,0,',','.') }}</small></div></div>
<strong class="comparison-price">${{ number_format($offer->price,0,',','.') }}</strong>
<a class="comparison-visit" href="{{ $offer->url }}" target="_blank" rel="noopener noreferrer" aria-label="Ver {{ $album->album_title }} en {{ $offer->store_name }}">Ver tienda<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M14 4h6v6m0-6L10 14"/><path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-5"/></svg></a>
</section>
@endforeach
</div>
<div class="comparison-all">@if($item['store_count']>count($item['offers']))<small>Mostrando las {{ count($item['offers']) }} tiendas más baratas de {{ $item['store_count'] }}.</small>@endif<a href="{{ route('album',[$album->album_id,$album->format,'condition'=>$album->condition,'currency'=>$filters['currency'],'comparison'=>1]) }}">Ver todas las tiendas <span aria-hidden="true">→</span></a></div>
</div>
</article>
@empty<div class="panel empty"><h2>No hay álbumes comparables con estos filtros</h2><p>La disponibilidad puede haber cambiado desde la última actualización del ranking.</p></div>@endforelse
</div>
{{ $products->links('pagination') }}
@endsection
