{{-- Halaman galat mandiri: tanpa layout toko, database, atau sesi, supaya tetap tampil saat 500 atau 503. Bahasa dibaca dari cookie pilihan toko. --}}
@php
	$loc = collect([request()->query('lang'), request()->cookie('tuffero_locale')])->first(fn ($v) => in_array($v, ['id', 'en'], true)) ?? 'id';
	$code = (string) $code;
	$retryAfter = isset($exception) && method_exists($exception, 'getHeaders') ? ($exception->getHeaders()['Retry-After'] ?? 0) : 0;
@endphp
<!DOCTYPE html>
<html lang="{{ $loc }}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title>{{ trans("errors.$code.title", [], $loc) }} | Tuffero</title>
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('images/Logo/Tuffero Icon - NoBckgrnd.webp') }}">
	<link rel="stylesheet" href="{{ asset('css/tuffero-error.css') }}?v={{ @filemtime(public_path('css/tuffero-error.css')) }}">
	@php
		// Font memakai manifest Vite; bila belum dibuat, halaman galat tetap tampil dengan font cadangan.
		try { echo app(\Illuminate\Foundation\Vite::class)->fonts(); } catch (\Throwable) {}
	@endphp
</head>
<body class="err-page">
	@include('errors.partials.topbar')
	@include('errors.partials.stage')
</body>
</html>
