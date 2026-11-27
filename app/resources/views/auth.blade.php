@extends('layout')
@section('content')<section class="auth panel"><span class="eyebrow">ADMINISTRACIÓN</span><h1>{{ $setup?'Tu colección empieza aquí.':'Qué bueno verte de nuevo.' }}</h1><p>{{ $setup?'Crea el administrador local de SoloDiscos. Después de este paso se cerrará el registro.':'Ingresa para gestionar tiendas y publicaciones.' }}</p><form method="post" action="{{ url('/login') }}">@csrf
@if($setup)<label>Tu nombre<input name="name" required value="{{ old('name') }}" autocomplete="name"></label>@endif
<label>Correo electrónico<input name="email" type="email" required value="{{ old('email') }}" autocomplete="username"></label>
<label>Contraseña<input name="password" type="password" required @if($setup) minlength="12" @endif autocomplete="{{ $setup?'new-password':'current-password' }}"></label>
@if($setup)<label>Repite la contraseña<input name="password_confirmation" type="password" required minlength="12" autocomplete="new-password"></label><p class="muted">Usa al menos 12 caracteres. Esta es la cuenta del panel, independiente de MySQL.</p>@endif
<button class="button">{{ $setup?'Crear administrador':'Ingresar' }}</button></form></section>@endsection
