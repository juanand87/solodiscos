@extends('layout')
@section('content')<div class="heading"><div><span class="eyebrow">CENTRO DE CONTROL</span><h1>Tus tiendas, conectadas.</h1><p>Administra las fuentes de tu catálogo y revisa cada actualización.</p></div><a class="button" href="{{ route('stores.create') }}">Agregar tienda</a></div>
<section class="panel"><div class="section-heading"><div><h2>Procesar trabajos pendientes</h2><p class="muted">Inicia las extracciones y comparaciones que están en cola. El avance se actualiza debajo.</p></div><form method="post" action="{{ route('admin.queue.start') }}" data-worker-launch>@csrf<button class="button">Procesar pendientes</button></form></div><small>Para iniciar nuevos trabajos automáticamente, mantén las tareas de cron configuradas en el hosting.</small></section>
<p id="live-progress-status" class="muted" role="status" aria-live="polite">Actualización en vivo · conectando…</p>
<div data-live-dashboard data-progress-url="{{ route('admin.progress') }}">@include('admin-live')</div>
<script src="{{ asset('admin-progress.js') }}?v=2" defer></script>
@endsection
