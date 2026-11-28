@extends('layout')
@section('content')
<nav class="discovery-breadcrumb" aria-label="Ruta de navegación"><a href="{{ route('catalog') }}">Inicio</a><span aria-hidden="true">›</span><span>Artistas</span></nav>
<div class="heading"><div><span class="eyebrow">EXPLORA EL CATÁLOGO</span><h1>Artistas en CD y vinilo</h1><p>Encuentra sus discos disponibles y compara las alternativas de las tiendas.</p></div></div>
<form class="panel discovery-search" method="get"><label for="artist-search">Buscar artista</label><div><input id="artist-search" name="q" value="{{ request('q') }}" maxlength="150" placeholder="Nombre del artista"><button class="button">Buscar</button></div></form>
<div class="discovery-directory">@forelse($artists as $artist)<a class="panel discovery-directory-item" href="{{ url($artist['path']) }}"><h2>{{ $artist['name'] }}</h2><span>{{ number_format($artist['count'],0,',','.') }} discos y formatos disponibles</span></a>@empty<p>No encontramos artistas con esos criterios.</p>@endforelse</div>
{{ $artists->links('pagination') }}
@endsection
