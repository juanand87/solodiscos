<div class="price-chart">
<svg viewBox="0 0 600 180" role="img" aria-label="Historial de precio en pesos chilenos, mínimo {{ number_format($chart['low'],0,',','.') }}, máximo {{ number_format($chart['high'],0,',','.') }}"><line x1="35" y1="120" x2="565" y2="120" stroke="#dce5d7"/><polyline points="{{ $chart['line'] }}" fill="none" stroke="#3d7046" stroke-width="2.5" stroke-linejoin="round"/>
@foreach($chart['points'] as $point)<circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="2.5" fill="#3d7046"><title>{{ Carbon\Carbon::parse($point['at'])->format('d/m/Y H:i') }} · ${{ number_format($point['price'],0,',','.') }}</title></circle>@endforeach
<text x="35" y="22">${{ number_format($chart['high'],0,',','.') }}</text><text x="565" y="145" text-anchor="end">${{ number_format($chart['low'],0,',','.') }}</text><text x="35" y="175">{{ Carbon\Carbon::parse($chart['first'])->format('d/m/Y') }}</text><text x="565" y="175" text-anchor="end">{{ Carbon\Carbon::parse($chart['last'])->format('d/m/Y') }}</text></svg>
<small>Precios observados. La línea une comprobaciones; los cambios entre ellas no se conocen.</small>
</div>
