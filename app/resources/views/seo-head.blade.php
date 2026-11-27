@php($seo=app(App\Services\SeoMetadata::class)->build(request(),['album'=>$album??null,'format'=>$format??null,'policy'=>$policy??null,'publications'=>$publications??null,'products'=>$products??null]))
<title>{{ $seo['title']??trim($__env->yieldContent('title','SoloDiscos')) }}</title>
<meta name="description" content="{{ $seo['description'] }}">
<meta name="robots" content="{{ $seo['robots'] }}">
@if($seo['canonical'])<link rel="canonical" href="{{ $seo['canonical'] }}">@endif
<meta property="og:site_name" content="SoloDiscos"><meta property="og:locale" content="es_CL"><meta property="og:type" content="website">
<meta property="og:title" content="{{ $seo['title']??'SoloDiscos' }}"><meta property="og:description" content="{{ $seo['description'] }}"><meta property="og:image" content="{{ $seo['image'] }}">
@if($seo['canonical'])<meta property="og:url" content="{{ $seo['canonical'] }}">@endif
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{{ $seo['title']??'SoloDiscos' }}"><meta name="twitter:description" content="{{ $seo['description'] }}"><meta name="twitter:image" content="{{ $seo['image'] }}">
@if($seo['graph'])<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@graph'=>$seo['graph']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) !!}</script>@endif
