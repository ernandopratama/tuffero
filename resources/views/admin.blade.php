<!DOCTYPE html>
<html lang="id">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title>Admin Tuffero</title>
	@if (\App\Support\PwaApp::forStaff(auth()->user()))
		{{-- D-144: aplikasi admin hanya ditawarkan ke staf yang sudah masuk; halaman masuk tidak memuatnya. --}}
		<link rel="manifest" href="/admin/manifest.webmanifest">
		<meta name="theme-color" content="#2B1B14">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-title" content="Tuffero Admin">
		<meta name="apple-mobile-web-app-status-bar-style" content="default">
		<link rel="apple-touch-icon" href="{{ asset('images/pwa/admin-apple-180.png') }}">
	@endif
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('images/Logo/Tuffero Icon - NoBckgrnd.webp') }}">
	@fonts
	@vite(['resources/css/admin.css', 'resources/js/app.js'])
</head>
<body>
	<div id="app"></div>
</body>
</html>
