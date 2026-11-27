@extends('portal.layout')
@section('portal-title','Oportunidad de mejora $')
@section('portal-content')
<form method="get" class="panel portal-filters"><label>Comparación<select name="mode"><option value="opportunities" @selected(($filters['mode']??'opportunities')==='opportunities')>Otras tiendas tienen menor precio</option><option value="best" @selected(($filters['mode']??'')==='best')>Tengo el precio mínimo (incluye empates)</option></select></label><label>Formato<select name="format"><option value="">CD y vinilo</option><option value="cd" @selected(($filters['format']??'')==='cd')>CD</option><option value="vinyl" @selected(($filters['format']??'')==='vinyl')>Vinilo</option></select></label><label>Condición<select name="condition"><option value="">Nuevos y usados</option><option value="new" @selected(($filters['condition']??'')==='new')>Nuevo</option><option value="used" @selected(($filters['condition']??'')==='used')>Usado</option></select></label><button class="button">Comparar</button></form>
<p class="portal-price-intro">{{ number_format($products->total(),0,',','.') }} productos comparables · CLP + envío. Mismo álbum, formato y condición; las ediciones pueden variar. Comprobados en las últimas 72 horas.</p>
<div class="portal-price-list">
@forelse($products as $product)
@php($difference=(float)$product->price-(float)$product->other_price)
@php($comparisonUrl=route('album',[$product->album_id,$product->format,'condition'=>$product->condition,'currency'=>'CLP','comparison'=>1]))
<article class="panel portal-price-card" data-album-id="{{ $product->album_id }}" data-format="{{ $product->format }}" data-condition="{{ $product->condition }}">
<div class="portal-price-summary">
<a class="portal-price-cover" href="{{ $comparisonUrl }}" aria-label="Comparar {{ $product->title }}">@if($product->image)<img src="{{ $product->image }}" alt="Portada de {{ $product->title }}" loading="lazy" referrerpolicy="no-referrer">@else<span class="disc" aria-label="Sin portada"></span>@endif</a>
<div class="portal-price-info"><span class="eyebrow">{{ $product->artist }}</span><h2><a href="{{ $comparisonUrl }}">{{ $product->title }}</a></h2>
<p class="portal-price-meta"><span class="tag">{{ $product->format==='cd'?'CD':'Vinilo' }}</span><span>{{ $product->condition==='new'?'Nuevo':'Usado' }}</span><span>{{ $product->other_stores+1 }} tiendas</span></p>
<p class="portal-own-price">Mi menor precio <strong>${{ number_format($product->price,0,',','.') }}</strong></p>
@if($difference>0)<p class="portal-price-difference">Diferencia: <strong>${{ number_format($difference,0,',','.') }}</strong> <span>({{ number_format(100*$difference/$product->other_price,1,',','.') }}% sobre el menor precio de otras tiendas)</span></p>
@elseif($difference==0)<p class="portal-price-position">Compartes el precio mínimo.</p>@else<p class="portal-price-position">Tu precio es el mínimo.</p>@endif
</div></div>
<div class="portal-price-competitors"><h3>Precios de otras tiendas</h3>
@foreach($product->competitors as $competitor)
@php($delta=(float)$competitor->price-(float)$product->price)
<div class="portal-competitor {{ (float)$competitor->price===(float)$product->other_price?'portal-competitor-lowest':'' }}" data-store-id="{{ $competitor->store_id }}">
<span class="portal-competitor-logo">@if($competitor->logo_url)<img src="{{ $competitor->logo_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else<span class="disc" aria-hidden="true"></span>@endif</span>
<div class="portal-competitor-name"><strong>{{ $competitor->store_name }}</strong><small>{{ $delta==0?'Empata contigo':'$'.number_format(abs($delta),0,',','.').($delta<0?' menos que tu precio':' más que tu precio') }}</small></div>
<strong class="portal-competitor-price">${{ number_format($competitor->price,0,',','.') }}</strong>
<a class="portal-competitor-visit" href="{{ $competitor->url }}" target="_blank" rel="noopener noreferrer" aria-label="Ver {{ $product->title }} en {{ $competitor->store_name }}">Ver tienda ↗</a>
</div>
@endforeach
<div class="portal-price-all"><small>{{ count($product->competitors) }} de {{ $product->other_stores }} otras tiendas · una oferta por tienda</small><a href="{{ $comparisonUrl }}">Ver todas y comparar →</a></div>
</div></article>
@empty<p class="panel">No hay productos con estos filtros. La identificación y la condición deben estar resueltas para poder comparar.</p>@endforelse
</div>{{ $products->links('pagination') }}
@endsection