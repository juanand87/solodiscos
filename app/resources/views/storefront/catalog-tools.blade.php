<div class="catalog-tools">
<div class="format-chips" aria-label="Filtrar formato">@foreach([''=>'Todo','vinyl'=>'Vinilos','cd'=>'CDs'] as $value=>$label)<a data-format="{{ $value }}" href="{{ route('catalog',array_merge(request()->except('page','format'),$value?['format'=>$value]:[])) }}#catalogo" @class(['format-chip','active'=>request('format','')===$value])>{{ $label }}</a>@endforeach</div>
<form class="catalog-display-options" method="get" action="{{ route('catalog') }}#catalogo">
@foreach(request()->only('q','format','condition') as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<label for="sort-catalog">Ordenar<select id="sort-catalog" name="sort" data-auto-submit><option value="artist" @selected(request('sort','artist')==='artist')>Artista A–Z</option><option value="stores" @selected(request('sort')==='stores')>Más tiendas para comparar</option><option value="recent" @selected(request('sort')==='recent')>Actualizados recientemente</option></select></label>
<label for="catalog-per-page">Resultados por página<select id="catalog-per-page" name="per_page" data-auto-submit>@foreach([25,50,100,250] as $size)<option value="{{ $size }}" @selected($perPage===$size)>{{ $size }} discos</option>@endforeach</select></label>
<noscript><button class="button secondary">Aplicar</button></noscript>
</form></div>
