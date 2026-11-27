@extends('portal.layout')
@section('portal-title','Configuración de correo SMTP')
@section('portal-content')
<p><a href="{{ route('admin') }}">← Volver a administración</a></p>
<section class="panel"><p>Esta configuración envía las verificaciones y recuperaciones del portal. Se requiere una conexión cifrada TLS. La contraseña se guarda cifrada con la clave de la aplicación; conserva esa clave al desplegar.</p><form method="post" action="{{ route('admin.smtp.save') }}" class="portal-settings">@csrf @method('PUT')
<input type="hidden" name="enabled" value="0"><label class="check"><input type="checkbox" name="enabled" value="1" @checked(old('enabled',$settings?->enabled??false))> Habilitar correo y registro de tiendas</label>
<label>Servidor SMTP<input name="host" required maxlength="253" value="{{ old('host',$settings?->host) }}" placeholder="smtp.proveedor.cl" autocomplete="off"></label>
<label>Puerto<input name="port" type="number" required min="1" max="65535" value="{{ old('port',$settings?->port??587) }}"></label>
<label>Seguridad<select name="scheme"><option value="smtp" @selected(old('scheme',$settings?->scheme??'smtp')==='smtp')>STARTTLS (habitualmente puerto 587)</option><option value="smtps" @selected(old('scheme',$settings?->scheme)==='smtps')>TLS directo (habitualmente puerto 465)</option></select></label>
<label>Usuario SMTP<input name="username" required maxlength="255" value="{{ old('username',$settings?->username) }}" autocomplete="off"></label>
<label>Contraseña SMTP<input name="password" type="password" maxlength="1000" autocomplete="new-password" @required(!$settings?->exists)><small>{{ $settings?'Deja vacío para conservar la contraseña guardada.':'Se guardará cifrada y no volverá a mostrarse.' }}</small></label>
<label>Correo remitente<input name="from_address" type="email" required maxlength="254" value="{{ old('from_address',$settings?->from_address) }}"></label>
<label>Nombre remitente<input name="from_name" required maxlength="100" value="{{ old('from_name',$settings?->from_name??'SoloDiscos') }}"></label><button class="button">Guardar configuración</button></form></section>
<section class="panel"><h2>Enviar correo de prueba</h2><p>Guarda y habilita primero la configuración. Un envío aceptado por SMTP no garantiza que llegue a la bandeja principal; comprueba recepción, spam y la configuración del dominio remitente.</p><form method="post" action="{{ route('admin.smtp.test') }}">@csrf<label>Destinatario de prueba<input name="test_email" type="email" required maxlength="254" value="{{ old('test_email') }}"></label><button class="button secondary">Enviar prueba</button></form></section>
@endsection
