@extends('store.layouts.app')

@php
	// Hanya foto produk asli Tuffero: [berkas di public/images/Produk, kategori, kunci label, besar?]. Foto dengan teks promosi besar sengaja tidak dipakai.
	$photos = [
		['Nastar Sultan 3', 'nastar', 'sultan', true], ['Macaron isi 50 3', 'macaron', 'mini'], ['Pia premium 3', 'pia', 'pia'], ['Dubai 3', 'snack', 'dubai'],
		['Nastar Apel 4', 'nastar', 'apel'], ['Meringue 3', 'snack', 'meringue'], ['Macaron isi 30 3', 'macaron', 'mini', true], ['Nastar Classic 5', 'nastar', 'classic'],
		['brownies susu 3', 'snack', 'brownies'], ['Nastar Sultan 2', 'nastar', 'sultan'], ['macaron regular 2', 'macaron', 'regular'], ['Pia premium 2', 'pia', 'pia', true],
		['Nastar Cookies 3', 'nastar', 'nastarCookies'], ['Cookies 3', 'snack', 'cookies'], ['selai 4', 'nastar', 'selai'], ['Macaron isi 50 1', 'macaron', 'mini'],
		['Nastar Sultan 7', 'nastar', 'sultan'], ['Meringue 1', 'snack', 'meringue'], ['Nastar Apel 1', 'nastar', 'apel', true], ['Pia premium 1', 'pia', 'pia'],
		['Dubai 2', 'snack', 'dubai'], ['Macaron New Packing 1', 'macaron', 'mini'], ['brownies susu 4', 'snack', 'brownies'], ['Nastar Classic 3', 'nastar', 'classic'],
		['rice strawberry 2', 'macaron', 'rice'], ['Nastar Cookies 2', 'nastar', 'nastarCookies'], ['Macaron Crumb 3', 'macaron', 'crumbs'], ['selai 2', 'nastar', 'selai'],
	];
	$videos = ['/videos/HeroVideo%20(1).mp4', '/videos/HeroVideo%20(2).mp4'];
	// Ikon kategori (public/images/Logo/icon, versi kecil di thumb/ untuk tab).
	$tabs = ['nastar' => 'nastar', 'macaron' => 'macaron', 'pia' => 'pia', 'snack' => 'camilan'];
@endphp

@section('content')
	<main>
		@include('store.partials.page-hero', ['hero' => $p['hero'], 'current' => $t['nav'][5], 'photo' => $site->productPhoto('Macaron isi 50 3')])

		<section class="tf-wrap tf-gal-section" x-data="gallery">
			<div x-ref="tabs" class="tf-cat-tabs wow fadeInUp" role="group" aria-label="{{ $p['filter'] }}">
				<button type="button" class="tf-tab" aria-label="{{ $p['all'] }}" data-tip="{{ $p['all'] }}" aria-pressed="true" :aria-pressed="cat === 'all'" @click="select('all')"><img src="/images/Logo/icon/thumb/semua.webp" alt="" width="81" height="54" decoding="async"></button>
				@foreach ($tabs as $key => $icon)
					<button type="button" class="tf-tab" aria-label="{{ $p['cats'][$key] }}" data-tip="{{ $p['cats'][$key] }}" aria-pressed="false" :aria-pressed="cat === '{{ $key }}'" @click="select('{{ $key }}')"><img src="/images/Logo/icon/thumb/{{ $icon }}.webp" alt="" width="81" height="54" decoding="async"></button>
				@endforeach
			</div>
			<!-- .gallery-items: lightbox Magnific dari function.js, sama seperti galeri landing -->
			<div x-ref="grid" class="tf-gal-grid gallery-items">
				@foreach ($photos as $photo)
					@php [$file, $cat, $label] = $photo; $big = $photo[3] ?? false; @endphp
					<a href="{{ $site->productPhoto($file) }}" data-cat="{{ $cat }}" @class(['tf-gal-item', 'is-big' => $big]) data-cursor-text="{{ $t['gallery']['view'] }}" aria-label="{{ $t['gallery']['view'] }}: {{ $p['labels'][$label] }}">
						<img src="/images/Produk/gallery/{{ $file }}.webp" alt="{{ $p['labels'][$label] }}" width="800" height="800" loading="lazy" decoding="async">
						<span>{{ $p['labels'][$label] }}</span>
					</a>
				@endforeach
			</div>
		</section>

		<section class="tf-page-band">
			<div class="tf-wrap">
				<div class="tf-head">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $p['video']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['video']['title']) }}</h2>
				</div>
				<div class="tf-gal-videos">
					@foreach ($videos as $i => $src)
						<a href="{{ $src }}" class="tf-gal-video popup-video wow fadeInUp" data-wow-delay="{{ ($i * 0.06).'s' }}" data-cursor-text="Play">
							<img src="/videos/poster-{{ $i + 1 }}.webp" alt="{{ $p['video']['items'][$i] }}" width="800" height="450" loading="lazy" decoding="async">
							<i aria-hidden="true">{{ $site->lineIcon('play', 26) }}</i>
							<span>{{ $p['video']['items'][$i] }}</span>
						</a>
					@endforeach
				</div>
			</div>
		</section>

		@include('store.partials.page-cta')
	</main>
@endsection
