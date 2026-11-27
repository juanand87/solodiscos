@extends('portal.layout')
@section('portal-title','Solicitar una corrección')
@section('portal-content')
<section class="panel"><h2>{{ $offer->title }}</h2><p>Describe el dato correcto y cómo comprobarlo. La solicitud no modifica automáticamente el catálogo. Precio y stock se actualizan desde tu sitio: corrígelos allí para que puedan recogerse en una extracción.</p>
<form method="post" action="{{ route('portal.correction',$offer) }}">@csrf<label>Dato que debemos revisar<select name="field" required>@foreach(App\Models\StoreCorrection::FIELDS as $key=>$label)<option value="{{ $key }}" @selected(old('field')===$key)>{{ $label }}</option>@endforeach</select></label><label>Corrección propuesta y motivo<textarea name="message" required minlength="10" maxlength="4000" rows="6">{{ old('message') }}</textarea></label><p>No incluyas datos de compradores, contraseñas ni información de pago.</p><button class="button">Enviar solicitud</button></form></section>
@endsection
