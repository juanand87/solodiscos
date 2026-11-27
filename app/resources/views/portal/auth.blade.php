@extends('portal.layout')
@section('portal-title',$mode==='register'?'Registra tu tienda':'Ingresa a tu tienda')
@section('portal-content')
<section class="panel portal-auth">
<p>Acceso exclusivo para tiendas activas incorporadas a SoloDiscos. Debes poder recibir correos en el dominio exacto de tu tienda.</p>
@if($mode==='register' && !$mailReady)<div class="notice">El registro estará disponible cuando el administrador configure el envío de correos.</div>@endif
<form method="post" action="{{ route($mode==='register'?'portal.register':'portal.login') }}" @if($mode==='register') data-store-registration data-mail-ready="{{ $mailReady?'1':'0' }}" @endif>@csrf
@if($mode==='register')
<label>Tienda<select name="store_id" required aria-describedby="registration-domain"><option value="">Selecciona tu tienda</option>@foreach($stores as $listedStore)<option value="{{ $listedStore->id }}" data-domain="{{ app(App\Services\StoreDomain::class)->host($listedStore) }}" @selected(old('store_id')==$listedStore->id)>{{ $listedStore->name }} · {{ app(App\Services\StoreDomain::class)->host($listedStore) }}</option>@endforeach</select><small id="registration-domain" class="registration-feedback" aria-live="polite">Selecciona tu tienda para conocer el dominio de correo requerido.</small></label>
<label>Tu nombre<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>
@endif
<label>Correo de la tienda<input name="email" type="email" required maxlength="254" autocomplete="username" value="{{ old('email') }}" placeholder="nombre@tutienda.cl" @if($mode==='register') aria-describedby="registration-email" @endif>@if($mode==='register')<small id="registration-email" class="registration-feedback" aria-live="polite"></small>@endif</label>
<label>Contraseña<input name="password" type="password" required @if($mode==='register') minlength="12" aria-describedby="registration-password" @endif autocomplete="{{ $mode==='register'?'new-password':'current-password' }}">@if($mode==='register')<small id="registration-password" class="registration-feedback" aria-live="polite"></small>@endif</label>
@if($mode==='register')<label>Repite la contraseña<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password" aria-describedby="registration-confirmation"><small id="registration-confirmation" class="registration-feedback" aria-live="polite"></small></label><p class="muted">Al menos 12 caracteres, con letras y números. Antes de acceder deberás confirmar el correo. Consulta nuestra <a href="{{ route('policies.show','privacidad') }}">política de privacidad</a>.</p>@endif
<button class="button" @disabled($mode==='register'&&!$mailReady)>{{ $mode==='register'?'Crear cuenta y verificar correo':'Ingresar' }}</button>
@if($mode==='register')<p class="registration-submit-hint muted" data-registration-hint>Completa los campos para crear tu cuenta.</p>@endif
</form>
<p><a href="{{ route($mode==='register'?'portal.login':'portal.register') }}">{{ $mode==='register'?'Ya tengo cuenta':'Registrar mi tienda' }}</a> · <a href="{{ route('portal.password.request') }}">Recuperar contraseña</a></p>
</section>
@if($mode==='register')<script src="{{ asset('store-registration.js') }}?v=1" defer></script>@endif
@endsection
