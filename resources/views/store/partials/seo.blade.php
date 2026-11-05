@php
	// Canonical tanpa parameter saring; versi Inggris memakai ?lang=en supaya bisa dirayapi tanpa cookie.
	$baseUrl = url()->current();
	$canonical = request()->query('lang') === 'en' ? $baseUrl.'?lang=en' : $baseUrl;
	$ogImage = isset($seo['image']) ? url($seo['image']) : url('/images/Tuffero Bakery Treats Showcase.webp');
@endphp
<title>{{ $seo['title'] }}</title>
@if (! empty($seo['description']))
	<meta name="description" content="{{ $seo['description'] }}">
@endif
@if (! empty($seo['noindex']))
	<meta name="robots" content="noindex, nofollow">
@else
	<link rel="canonical" href="{{ $canonical }}">
	<link rel="alternate" hreflang="id" href="{{ $baseUrl }}">
	<link rel="alternate" hreflang="en" href="{{ $baseUrl }}?lang=en">
	<link rel="alternate" hreflang="x-default" href="{{ $baseUrl }}">
	<meta property="og:site_name" content="Tuffero Bakery">
	<meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
	<meta property="og:locale" content="{{ $locale === 'en' ? 'en_US' : 'id_ID' }}">
	<meta property="og:title" content="{{ $seo['title'] }}">
	@if (! empty($seo['description']))
		<meta property="og:description" content="{{ $seo['description'] }}">
	@endif
	<meta property="og:url" content="{{ $canonical }}">
	<meta property="og:image" content="{{ str_replace(' ', '%20', $ogImage) }}">
	<meta name="twitter:card" content="summary_large_image">
@endif
@if (! empty($seo['jsonld']))
	<script type="application/ld+json">{{ $site->json($seo['jsonld']) }}</script>
@endif
