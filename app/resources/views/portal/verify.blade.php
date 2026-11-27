@extends('portal.layout')
@section('portal-title','Confirma tu correo')
@section('portal-content')
<section class="panel portal-auth"><p>Para acceder a <strong>{{ $account->store->name }}</strong>, abre el enlace enviado a <strong>{{ $account->email }}</strong>. Debes ingresar con esta misma cuenta al abrirlo.</p><p>El enlace caduca en 60 minutos. Revisa también la carpeta de spam.</p><form method="post" action="{{ route('portal.resend') }}">@csrf<button class="button">Reenviar verificación</button></form></section>
@endsection
