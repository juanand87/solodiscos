@extends('layout')
@section('title','Ventas integradas · SoloDiscos')
@section('content')
<h1>Ventas por tienda</h1>
<form method="get" class="panel"><label>Desde<input type="date" name="from" value="{{ $from }}" required></label><label>Hasta<input type="date" name="to" value="{{ $to }}" required></label><button class="button">Consultar</button></form>
<p>Ventas atribuidas a SoloDiscos sincronizadas por WooCommerce, por fecha de creación UTC. Incluye impuestos y envío; neto = total menos devoluciones. La atribución es la informada por el plugin; no representa una auditoría bancaria.</p>
@foreach($stores as $store)<section class="panel"><h2>{{ $store->name }}</h2><p>{{ $store->integration?->token_hash ? 'Clave activa' : 'Sin conexión activa' }} · Último envío: {{ $store->integration?->last_sync_at ?? 'Sin envíos' }} (UTC)</p>
@forelse($store->sales_summary as $row)<p>{{ $row->currency }} · {{ $row->orders }} pedidos · Total: {{ number_format($row->gross,2,',','.') }} · Devoluciones: {{ number_format($row->refunded,2,',','.') }} · Neto: {{ number_format($row->net,2,',','.') }} · {{ $row->units }} unidades netas · Ticket neto: {{ number_format($row->average,2,',','.') }}</p>@empty<p>Sin ventas pagadas recibidas en este período.</p>@endforelse</section>@endforeach
{{ $stores->links() }}
@endsection
