@extends('portal.layout')
@section('portal-title','Estadísticas de mi tienda')
@section('portal-content')
<form class="panel portal-filters" method="get"><label>Desde<input type="date" name="from" required value="{{ $filters['from'] }}"></label><label>Hasta<input type="date" name="to" required value="{{ $filters['to'] }}"></label><label>Formato<select name="format"><option value="">CD y vinilo</option><option value="cd" @selected(($filters['format']??'')==='cd')>CD</option><option value="vinyl" @selected(($filters['format']??'')==='vinyl')>Vinilo</option></select></label><button class="button">Aplicar filtros</button><a href="{{ route('portal.statistics') }}">Restablecer</a></form>
@include('engagement-dashboard')
@endsection
