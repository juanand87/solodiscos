@extends('layout')
@section('title',$policy['title'].' · SoloDiscos')
@section('content')
<link rel="stylesheet" href="{{ asset('policies.css') }}?v=1">
<div class="policy-layout">
<header class="policy-header"><span class="eyebrow">Información sobre SoloDiscos.cl</span><h1>{{ $policy['title'] }}</h1><p class="policy-intro">{{ $policy['intro'] }}</p><small>Última actualización: {{ config('policies.updated_at') }}</small></header>
<nav class="policy-navigation" aria-label="Políticas de SoloDiscos">
@foreach(config('policies.pages') as $key=>$page)<a href="{{ route('policies.show',$key) }}" @if($key===$slug) aria-current="page" @endif>{{ $page['label'] }}</a>@endforeach
</nav>
<article class="policy-content">
@foreach($policy['sections'] as $section)
<section><h2>{{ $section['title'] }}</h2>
@foreach($section['paragraphs']??[] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
@if(!empty($section['items']))<ul>@foreach($section['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif
</section>
@endforeach
<p class="policy-back"><a href="{{ route('catalog') }}">← Volver al catálogo</a></p>
</article>
</div>
@endsection
