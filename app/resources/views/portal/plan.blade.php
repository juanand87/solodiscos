@extends('portal.layout')
@section('portal-title','Plan Tienda Asociada')
@section('portal-content')
<section class="panel"><span class="eyebrow">PROGRAMA DE TIENDAS ASOCIADAS SOLODISCOS</span>
@if($store->commercial_agreement_reference)<p>Referencia de firma registrada por SoloDiscos: {{ $store->commercial_agreement_reference }} · {{ $store->commercial_agreement_signed_at }}. Este registro administrativo no verifica automáticamente la firma.</p>@endif
@if($store->commercial_agreement_plugin_sha256)<p style="overflow-wrap:anywhere">Paquete vinculado al registro del acuerdo: SHA-256 <code>{{ $store->commercial_agreement_plugin_sha256 }}</code>.</p>@endif
@if($store->partner_plan_requested_at)<p>Solicitud de formalización registrada: {{ $store->partner_plan_requested_at }} · versión {{ $store->partner_plan_requested_version }}.</p>@endif
@if($store->commercial_agreement_terminated_at)<p>Último término registrado: {{ $store->commercial_agreement_terminated_at }}.</p>@endif
</section>
<section class="panel"><h2>Acuerdo para revisión</h2><p><strong>Borrador · versión {{ $plan['version'] }}</strong>. La lectura o la solicitud desde esta página no constituye firma ni aceptación contractual. La activación exige formalización entre las partes y revisión de SoloDiscos.</p>
<p>SoloDiscos: <strong>{{ $plan['legal_identity']['name'] }}</strong>, RUT {{ $plan['legal_identity']['rut'] }}. Por completar antes de firmar: representante legal, domicilio y canales de contacto de SoloDiscos, y los datos legales y representación de la tienda.</p>
@if($plan['commission_basis']==='pending')<p>La base exacta del 4% y el tratamiento del envío e impuestos están pendientes de definición en el documento firmado.</p>@endif
@foreach($plan['sections'] as $section)<h3>{{ $section['title'] }}</h3><p>{{ $section['text'] }}</p>@endforeach
<p><a href="{{ route('portal.integration.download') }}">Descargar plugin y código fuente (.zip)</a> · <a href="{{ route('portal.integration') }}">Ver integración y datos enviados</a></p>
<p style="overflow-wrap:anywhere">Paquete disponible: versión {{ $plan['plugin_version'] }} · SHA-256 <code>{{ $pluginHash??'No disponible' }}</code>. La versión y esta huella permiten identificar el paquete revisado y deben incorporarse al documento a formalizar; no constituyen una certificación de seguridad.</p>
@unless($partnerAccess['agreement_active'])<form method="post" action="{{ route('portal.plan.request') }}">@csrf<button class="button">Solicitar formalización del acuerdo</button></form>@endunless
</section>
@if($partnerAccess['agreement_active']||$partnerAccess['plugin_connected']||$store->partner_plan_requested_at)<section class="panel"><h2>Terminar el acuerdo</h2><p>Puedes terminarlo sin permanencia mínima. Se desactiva el acceso completo y se revoca la clave para rechazar nuevas sincronizaciones. Las operaciones anteriores quedan sujetas a la conciliación y conservación definidas en el acuerdo firmado.</p><form method="post" action="{{ route('portal.plan.terminate') }}">@csrf<label class="check"><input type="checkbox" name="confirm_termination" value="1" required> Quiero comunicar el término y desconectar la integración de mi tienda.</label><button class="button secondary">Terminar acuerdo y desconectar</button></form></section>@endif
@endsection
