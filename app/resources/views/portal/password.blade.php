@extends('portal.layout')
@section('portal-title',$reset?'Nueva contraseña':'Recupera tu acceso')
@section('portal-content')
<section class="panel portal-auth"><form method="post" action="{{ route($reset?'portal.password.update':'portal.password.email') }}">@csrf
<label>Correo de la tienda<input name="email" type="email" required autocomplete="username" value="{{ old('email',$email??'') }}"></label>
@if($reset)<input type="hidden" name="token" value="{{ $token }}"><label>Nueva contraseña<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>Repite la contraseña<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label><p>Usa al menos 12 caracteres, con letras y números.</p>@endif
<button class="button">{{ $reset?'Guardar contraseña':'Enviar enlace de recuperación' }}</button></form><p><a href="{{ route('portal.login') }}">Volver al ingreso</a></p></section>
@endsection
