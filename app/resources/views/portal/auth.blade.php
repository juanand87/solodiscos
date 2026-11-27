@extends('portal.layout')
@section('portal-title',$mode==='register'?'Registra tu tienda':'Ingresa a tu tienda')
@section('portal-content')
<section class="panel portal-auth">
<p>Acceso exclusivo para tiendas activas incorporadas a SoloDiscos. Debes poder recibir correos en el dominio exacto de tu tienda.</p>
@if($mode==='register' && !$mailReady)<div class="notice">El registro estará disponible cuando el administrador configure el envío de correos.</div>@endif
<form method="post" action="{{ route($mode==='register'?'portal.register':'portal.login') }}">@csrf
@if($mode==='register')
<label>Tienda<select name="store_id" required><option value="">Selecciona tu tienda</option>@foreach($stores as $listedStore)<option value="{{ $listedStore->id }}" @selected(old('store_id')==$listedStore->id)>{{ $listedStore->name }} · {{ app(App\Services\StoreDomain::class)->host($listedStore) }}</option>@endforeach</select></label>
<label>Tu nombre<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>
@endif
<label>Correo de la tienda<input name="email" type="email" required maxlength="254" autocomplete="username" value="{{ old('email') }}" placeholder="nombre@tutienda.cl"></label>
<label>Contraseña<input name="password" type="password" required @if($mode==='register') minlength="12" @endif autocomplete="{{ $mode==='register'?'new-password':'current-password' }}"></label>
@if($mode==='register')<label>Repite la contraseña<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label><p class="muted">Al menos 12 caracteres, con letras y números. Antes de acceder deberás confirmar el correo. Consulta nuestra <a href="{{ route('policies.show','privacidad') }}">política de privacidad</a>.</p>@endif
<button class="button" @disabled($mode==='register'&&!$mailReady)>{{ $mode==='register'?'Crear cuenta y verificar correo':'Ingresar' }}</button>
</form>
<p><a href="{{ route($mode==='register'?'portal.login':'portal.register') }}">{{ $mode==='register'?'Ya tengo cuenta':'Registrar mi tienda' }}</a> · <a href="{{ route('portal.password.request') }}">Recuperar contraseña</a></p>
</section>
@endsection
