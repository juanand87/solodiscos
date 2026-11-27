@extends('portal.layout')
@section('portal-title','Integración con WooCommerce')
@section('portal-content')
<section class="panel"><h2>Conecta tu tienda</h2><p>Recibe las estadísticas de SoloDiscos en WooCommerce y consulta aquí las ventas que sincronice tu tienda.</p>
<p>Estado: <strong>{{ $integration?->token_hash ? ($integration->last_sync_at ? 'Con datos recibidos' : 'Esperando primera sincronización') : 'Desconectada' }}</strong>. Último envío recibido: {{ $integration?->last_sync_at ?? 'Todavía no registrado' }} (UTC).</p>
<ol><li><a href="{{ route('portal.integration.download') }}">Descargar plugin de WooCommerce (.zip)</a> e instalar desde WordPress → Plugins → Añadir plugin → Subir plugin.</li><li>Genera una clave y cópiala en WooCommerce → SoloDiscos.</li><li>Guarda y conecta. Los pedidos se enviarán en segundo plano. Puedes importar el historial desde el plugin.</li></ol>
<p>Dirección de SoloDiscos: <code>{{ url('/') }}</code></p>
@if(session('integration_token'))<label>Tu nueva clave (visible una sola vez)<input type="text" readonly value="{{ session('integration_token') }}" style="width:100%" autocomplete="off" spellcheck="false"></label>@endif
<form method="post" action="{{ route('portal.integration.rotate') }}">@csrf<button class="button">{{ $integration?->token_hash ? 'Reemplazar clave' : 'Generar clave' }}</button></form>
@if($integration?->token_hash)<form method="post" action="{{ route('portal.integration.revoke') }}">@csrf @method('DELETE')<button class="link">Desconectar integración</button></form>@endif
<p>Reemplazar la clave desconecta cualquier instalación que use la anterior. La clave permite enviar ventas y consultar estadísticas de esta tienda; guárdala como una contraseña.</p></section>
<section class="panel"><h2>Ventas sincronizadas</h2>
<form method="get" class="portal-filters"><label>Desde<input type="date" name="from" value="{{ $from }}" required></label><label>Hasta<input type="date" name="to" value="{{ $to }}" required></label><button class="button">Consultar</button></form>
<p>Pedidos pagados, excluyendo cancelados y fallidos, según fecha de creación (UTC). Incluye impuestos y envío; neto = total menos devoluciones. Corresponde a todas las ventas de WooCommerce y no atribuye compras a SoloDiscos. Cada moneda se calcula por separado.</p>
@forelse($sales as $row)<div class="portal-cards">@foreach(['orders'=>'Pedidos','gross'=>'Total','refunded'=>'Devoluciones','net'=>'Neto','units'=>'Unidades netas','average'=>'Ticket promedio neto'] as $key=>$label)<div class="panel"><span>{{ $label }} · {{ $row->currency }}</span><strong>{{ number_format($row->$key, in_array($key,['orders','units'])?0:2,',','.') }}</strong></div>@endforeach</div>@empty<p>Sin ventas pagadas recibidas para este período. Esto no significa que la tienda no haya vendido.</p>@endforelse
<p>Se reciben únicamente identificador de pedido, estado, fechas, moneda, importes y cantidades. No se reciben datos personales de compradores. El catálogo y las estadísticas actuales siguen disponibles en las secciones del portal.</p></section>
@endsection
