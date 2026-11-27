@extends('layout')
@section('content')<div class="heading"><div><span class="eyebrow">CENTRO DE CONTROL</span><h1>Tus tiendas, conectadas.</h1><p>Administra las fuentes de tu catálogo y revisa cada actualización.</p></div><a class="button" href="{{ route('stores.create') }}">Agregar tienda</a></div>
<p id="live-progress-status" class="muted" role="status" aria-live="polite">Actualización en vivo · conectando…</p>
<div data-live-dashboard data-progress-url="{{ route('admin.progress') }}">@include('admin-live')</div>
<script src="{{ asset('admin-progress.js') }}" defer></script>
@endsection