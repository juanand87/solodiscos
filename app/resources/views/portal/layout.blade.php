@extends('layout')
@section('title','Portal de tiendas · SoloDiscos')
@section('content')
<link rel="stylesheet" href="{{ asset('portal.css') }}?v=4">
<div class="portal-shell">
<div class="heading"><div><span class="eyebrow">PORTAL DE TIENDAS</span><h1>@yield('portal-title','Tu tienda en SoloDiscos')</h1>@isset($store)<p>{{ $store->name }}</p>@endisset</div></div>
@if(Auth::guard('store')->check())
<nav class="portal-nav" aria-label="Portal de tiendas"><a href="{{ route('portal.dashboard') }}">Resumen</a><a href="{{ route('portal.catalog') }}">Mi catálogo</a><a href="{{ route('portal.prices') }}">Oportunidad de mejora $</a><a href="{{ route('portal.statistics') }}">Estadísticas</a><a href="{{ route('portal.corrections') }}">Solicitudes</a><form method="post" action="{{ route('portal.logout') }}">@csrf<button class="link">Salir del portal</button></form></nav>
@endif
@yield('portal-content')
</div>
@endsection
