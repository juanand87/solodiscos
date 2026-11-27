@extends('layout')
@section('title','Historial de precios · '.$offer->title.' · SoloDiscos')
@section('content')
<link rel="stylesheet" href="{{ asset('discounts.css') }}?v=1">
<a class="catalog-back-button" href="{{ route('discounts',['store_id'=>$offer->store_id]) }}">← Descuentos de {{ $offer->store->name }}</a>
<section class="panel"><span class="eyebrow">{{ $offer->store->name }}</span><h1>{{ $offer->title }}</h1><p>{{ $offer->format==='cd'?'CD':'Vinilo' }} · {{ ['new'=>'Nuevo','used'=>'Usado'][$offer->condition]??'Sin especificar' }} · Precio actual: <strong>${{ number_format($offer->price,0,',','.') }}</strong> + envío</p>
@if($baseline && $baseline->drop_percent>0)<p class="discount-percent">↓ {{ number_format($baseline->drop_percent,1,',','.') }}% desde el primer precio válido de ${{ number_format($baseline->initial_price,0,',','.') }}, registrado el {{ Carbon\Carbon::parse($baseline->initial_at)->format('d/m/Y H:i') }}.</p>@endif
@include('price-chart')<p class="discount-note">Gráfico resumido de comprobaciones registradas. El listado conserva todas las observaciones válidas en CLP de esta publicación, incluidas aquellas sin cambios de precio. Son precios de la misma publicación de la tienda; no certificamos que su edición haya permanecido idéntica.</p></section>
@include('purchase-notice')
<section class="panel"><h2>Historial completo</h2><div class="table-wrap"><table><thead><tr><th>Fecha de comprobación</th><th>Precio CLP</th><th>Estado informado</th></tr></thead><tbody>@forelse($observations as $point)<tr><td>{{ Carbon\Carbon::parse($point->created_at)->format('d/m/Y H:i') }}</td><td>${{ number_format($point->price,0,',','.') }}</td><td>{{ ['in_stock'=>'Con stock','out_of_stock'=>'Sin stock'][$point->availability]??'Sin confirmar' }}</td></tr>@empty<tr><td colspan="3">Todavía no hay observaciones de precio válidas en CLP anteriores a la comprobación actual.</td></tr>@endforelse</tbody></table></div></section>{{ $observations->links('pagination') }}
@endsection
