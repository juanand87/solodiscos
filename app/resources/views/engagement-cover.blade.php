@if($item->album_id && in_array($item->format,['cd','vinyl']))
<button type="button" class="engagement-cover" data-disc-detail="{{ route(request()->routeIs('portal.*')?'portal.statistics.disc':'statistics.disc',array_filter(['album'=>$item->album_id,'format'=>$item->format,'store_id'=>$filters['store_id']??null,'offer_id'=>$item->offer_id??null])) }}" aria-label="Ver portada y clics de {{ $item->title }}">
@if($item->image??null)<img src="{{ $item->image }}" alt="" loading="lazy" referrerpolicy="no-referrer">@else<span aria-hidden="true">♫</span>@endif
</button>
@endif
