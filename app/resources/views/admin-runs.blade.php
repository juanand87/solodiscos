<section class="panel"><h2>Últimas extracciones</h2><p class="muted">Las extracciones activas aparecen primero. Los trabajos en cola necesitan que el trabajador local esté ejecutándose.</p>
<div class="table-wrap"><table><thead><tr><th>Tienda</th><th>Resultado</th><th>Ofertas procesadas</th><th>Página</th><th>Tiempo</th><th>Detalle</th></tr></thead><tbody>
@forelse($runs as $run)
@php($busy=in_array($run->status,['queued','running']))
@php($recovered=$run->status==='failed' && $run->finished_at && $run->store->last_success_at?->gt($run->finished_at))
@php($seconds=$run->started_at?max(0,(int)$run->started_at->diffInSeconds($run->finished_at??now())):null)
<tr data-run-id="{{ $run->id }}"><td><strong>{{ $run->store->name }}</strong><small>{{ $run->created_at->format('d/m H:i') }}</small></td>
<td><span class="tag {{ $run->status==='failed'&&!$recovered?'danger':'' }}">{{ $recovered?'Fallo anterior':(['queued'=>'En cola','running'=>'En curso','success'=>'Completada','failed'=>'Falló','warning'=>'Con observaciones','cancelled'=>'Cancelada'][$run->status] ?? $run->status) }}</span>
@if($busy)<div class="progress-sweep" role="progressbar" aria-label="{{ $run->status==='queued'?'Esperando trabajador':'Extracción en curso, total aún desconocido' }}"><span></span></div>@endif</td>
<td><strong>{{ number_format($run->imported,0,',','.') }}</strong><small>{{ $busy?'En esta ejecución':'Procesadas en esta ejecución' }}</small></td>
<td>{{ $run->current_page ?? '—' }}</td>
<td>{{ $seconds===null?'—':($seconds>=60?intdiv($seconds,60).' min '.($seconds%60).' s':$seconds.' s') }}</td>
<td>@if($recovered)<strong>La tienda se procesó nuevamente después de este fallo.</strong><small>Última extracción completada: {{ $run->store->last_success_at->format('d/m/Y H:i') }}. Consulta ese resultado para ver las observaciones actuales.</small><details><summary>Ver detalle del intento anterior</summary><p class="description">{{ $run->message }}</p></details>@else{{ $run->message ?? ($run->status==='queued'?'Esperando al trabajador.':($run->current_page?'Consultando o procesando la página '.$run->current_page.'.':'Preparando la extracción.')) }}@endif
@if($run->heartbeat_at)<small>Último avance: {{ $run->heartbeat_at->format('H:i:s') }}</small>@endif</td></tr>
@empty<tr><td colspan="6">Todavía no hay extracciones.</td></tr>@endforelse
</tbody></table></div></section>
