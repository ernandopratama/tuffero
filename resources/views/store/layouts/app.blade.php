<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	@include('store.partials.seo')
	<link rel="shortcut icon" type="image/x-icon" href="{{ asset('images/Logo/Tuffero Icon - NoBckgrnd.webp') }}">
	<link href="{{ asset('css/bootstrap.min.css') }}" rel="stylesheet" media="screen">
	<link rel="stylesheet" href="{{ asset('css/swiper-bundle.min.css') }}">
	<link href="{{ asset('css/animate.css') }}" rel="stylesheet">
	<link rel="stylesheet" href="{{ asset('css/magnific-popup.css') }}">
	<link rel="stylesheet" href="{{ asset('css/mousecursor.css') }}">
	<link href="{{ asset('css/custom.css') }}" rel="stylesheet" media="screen">
	<link href="{{ asset('css/tuffero-landing.css') }}?v={{ filemtime(public_path('css/tuffero-landing.css')) }}" rel="stylesheet" media="screen">
	<link href="{{ asset('css/tuffero-loader.css') }}?v={{ filemtime(public_path('css/tuffero-loader.css')) }}" rel="stylesheet">

	@fonts
	<script src="{{ asset('js/tuffero-loader.js') }}?v={{ filemtime(public_path('js/tuffero-loader.js')) }}" defer></script>
	<noscript><style>.tf-page-loader, .tuffero-loader { display: none !important; } .wow { visibility: visible !important; } .tf-cat-card[hidden] { display: flex !important; }</style></noscript>
	<script>
	// Loader halaman toko. Dua loader, satu per muat halaman:
	// - hari ini belum pernah: preloader logo (js/tuffero-loader.js), sekali saja per hari (localStorage, tanggal lokal);
	// - selebihnya: loader "LOADING" pada setiap muat halaman (klik tautan, refresh, URL langsung) sampai skrip template siap.
	// Kelas dipasang sebelum paint pertama. window.tufferoLoaded dibaca legacy.js dan store/home.js.
	(function () {
		var html = document.documentElement, KEY = 'tf-page-nav', DAY = 'tuffero-loader-day', MIN_MS = 350, MAX_MS = 6000;
		var d = new Date(), pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var today = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
		window.tufferoLoaderDay = { key: DAY, today: today };
		var seen;
		try { seen = localStorage.getItem(DAY) === today; } catch (e) {
			try { seen = sessionStorage.getItem(DAY) === today; } catch (e2) { seen = true; /* penyimpanan diblokir: pakai loader ringan */ }
		}
		var hide = function () { html.classList.remove(KEY); };
		if (!seen) {
			html.classList.add('tf-first');
		} else {
			html.classList.add(KEY);
			var finish;
			window.tufferoLoaded = new Promise(function (resolve) { finish = function () { hide(); resolve(); }; });
			addEventListener('tuffero:app-ready', function () { setTimeout(finish, Math.max(0, MIN_MS - performance.now())); }, { once: true });
			setTimeout(finish, MAX_MS);
		}
		// Fase bubble: tautan yang ditangani popup sudah defaultPrevented dan dilewati.
		document.addEventListener('click', function (e) {
			var a = e.target.closest && e.target.closest('a[href]');
			if (!a || e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || a.target === '_blank' || a.hasAttribute('download')) return;
			var to = new URL(a.href, location.href);
			if (to.origin !== location.origin || (to.pathname === location.pathname && to.search === location.search) || /^\/(login|admin)|\.[a-z0-9]+$/i.test(to.pathname)) return;
			html.classList.add(KEY);
			setTimeout(hide, 8000);
		});
		// Kembali lewat tombol Back (bfcache): halaman lama dipulihkan apa adanya, jadi loader harus ditutup.
		addEventListener('pageshow', function (e) { if (e.persisted) hide(); });
	})();
	</script>
	@vite(['resources/css/app.css', 'resources/js/store.js'])
</head>
<body>
	@include('store.partials.loaders')
	<script type="application/json" id="tf-store-data">{{ $site->json(['cartCount' => $cartCount, 'cart' => \Illuminate\Support\Arr::only($pg['cart'], ['added', 'addUnavailable', 'addFailed'])]) }}</script>
	<div class="tf" @yield('root')>
		@include('store.partials.header', ['mainbar' => $mainbar ?? true])
		@yield('content')
		@if ($footer ?? true)
			@include('store.partials.footer')
		@endif
		<button type="button" class="tf-top" aria-label="{{ $t['aria']['top'] }}" inert>
			<svg class="tf-top-icon" viewBox="0 0 384 512" aria-hidden="true"><path d="M214.6 41.4c-12.5-12.5-32.8-12.5-45.3 0l-160 160c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L160 141.2V448c0 17.7 14.3 32 32 32s32-14.3 32-32V141.2L329.4 246.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3l-160-160z"></path></svg>
			<span class="tf-top-label" aria-hidden="true">{{ $t['aria']['top'] }}</span>
		</button>
		@include('store.partials.chat')
		<div class="tf-cat-toast" x-data :class="$store.cart.toast ? 'is-on' : ''" role="status" x-text="$store.cart.toast"></div>
	</div>
</body>
</html>
