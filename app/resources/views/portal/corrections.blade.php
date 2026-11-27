@extends('portal.layout')
@section('portal-title','Solicitudes y respuestas')
@section('portal-content')
@forelse($corrections as $correction)<article class="panel"><span class="tag">{{ App\Models\StoreCorrection::STATUSES[$correction->status] }}</span><h2>{{ $correction->offer->title }}</h2><p>{{ App\Models\StoreCorrection::FIELDS[$correction->field] }} · {{ $correction->created_at->format('d/m/Y H:i') }}</p><p class="portal-message">{{ $correction->message }}</p>@if($correction->response)<h3>Respuesta de SoloDiscos</h3><p class="portal-message">{{ $correction->response }}</p><small>{{ $correction->reviewed_at?->format('d/m/Y H:i') }}</small>@endif</article>@empty<p class="panel">Todavía no hay solicitudes. Puedes crear una desde cualquier publicación de tu catálogo.</p>@endforelse
{{ $corrections->links('pagination') }}
@endsection
