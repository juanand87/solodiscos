@extends('layout')
@section('content')
<nav class="discovery-breadcrumb" aria-label="Ruta de navegación"><a href="{{ route('catalog') }}">Inicio</a><span aria-hidden="true">›</span><span>Tiendas de discos</span></nav>
<div class="heading"><div><span class="eyebrow">DISTINTAS TIENDAS, UN CATÁLOGO</span><h1>Tiendas de discos en Chile</h1><p>Explora los CDs y vinilos disponibles de cada tienda y compara sus publicaciones en SoloDiscos.</p></div></div>
<div class="discovery-directory">@forelse($publicStores as $publicStore)<a class="panel discovery-directory-item" href="{{ url(app(\App\Services\CatalogDiscovery::class)->storePath($publicStore)) }}">@if($publicStore->logo_url)<img class="discovery-store-logo" src="{{ $publicStore->logo_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">@endif<h2>{{ $publicStore->name }}</h2><span>Ver catálogo y comparar discos →</span></a>@empty<p>Todavía no hay tiendas con publicaciones disponibles.</p>@endforelse</div>
@endsection
