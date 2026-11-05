@extends('store.layouts.app')

@php
	$statValues = [$site->years(), $site::RATING, 4, 6];
	$valueIcons = ['pineapple', 'nastar', 'redeem'];
	$rangeCats = ['nastar', 'macaron', 'pia', 'snack'];
	$rangePhotos = ['Nastar Sultan 1', 'Macaron isi 50 1', 'Pia premium 1', 'Dubai 1'];
	$delay = fn ($i) => ($i * 0.06).'s';
@endphp

@section('content')
	<main>
		@include('store.partials.page-hero', ['hero' => $p['hero'], 'current' => $t['nav'][1], 'photo' => $site->productPhoto('Nastar Classic 2')])

		<!-- Cerita: susunan sama dengan bagian Tentang Kami di landing -->
		<section class="tf-page-section tf-wrap">
			<div class="tf-about-top">
				<div class="tf-about-copy">
					<p class="tf-eyebrow wow fadeInUp">{{ $p['story']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-spaced text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['story']['title']) }}</h2>
					<p class="tf-lead wow fadeInUp">{{ $p['story']['p1'] }}</p>
					<p class="tf-lead wow fadeInUp" data-wow-delay="0.06s">{{ $p['story']['p2'] }}</p>
					<ul class="tf-checks wow fadeInUp" data-wow-delay="0.1s">
						@foreach ($p['story']['checks'] as $item)
							<li><span>{{ $site->icon('check', 14) }}</span>{{ $item }}</li>
						@endforeach
					</ul>
				</div>
				<div class="tf-about-images">
					<figure class="image-anime wow fadeInUp"><img src="{{ $site->productPhoto('Nastar Sultan 2') }}" alt="{{ $p['story']['photo1'] }}" loading="lazy"></figure>
					<figure class="image-anime wow fadeInUp" data-wow-delay="0.08s"><img src="{{ $site->productPhoto('Macaron isi 50 1') }}" alt="{{ $p['story']['photo2'] }}" loading="lazy"></figure>
					<div class="tf-years"><strong><span class="counter">{{ $site->years() }}</span></strong><span>{{ $site->rich($t['about']['years']) }}</span></div>
				</div>
			</div>
		</section>

		<!-- Angka -->
		<section class="tf-wrap">
			<ul class="tf-page-stats wow fadeInUp">
				@foreach ($statValues as $i => $value)
					<li>
						<strong><span @class(['counter' => is_int($value)])>{{ $value }}</span></strong>
						<span>{{ $p['stats'][$i] }}</span>
					</li>
				@endforeach
			</ul>
		</section>

		<!-- Yang dijaga -->
		<section class="tf-page-section tf-wrap">
			<div class="tf-head">
				<p class="tf-eyebrow wow fadeInUp">{{ $p['values']['eyebrow'] }}</p>
				<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['values']['title']) }}</h2>
			</div>
			<div class="tf-value-grid">
				@foreach ($p['values']['items'] as $i => [$title, $text])
					<article class="tf-feature wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
						<span class="tf-round tf-round-lg">{{ $site->icon($valueIcons[$i], 24) }}</span>
						<h3>{{ $title }}</h3>
						<p>{{ $text }}</p>
					</article>
				@endforeach
			</div>
		</section>

		<!-- Kreasi -->
		<section class="tf-page-band">
			<div class="tf-wrap">
				<div class="tf-head">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $p['range']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['range']['title']) }}</h2>
				</div>
				<div class="tf-range-grid">
					@foreach ($p['range']['items'] as $i => [$title, $text])
						<a href="{{ $site->route('products') }}?kategori={{ $rangeCats[$i] }}" class="tf-range wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
							<img src="{{ $site->productPhoto($rangePhotos[$i]) }}" alt="" loading="lazy">
							<div>
								<h3>{{ $title }}</h3>
								<p>{{ $text }}</p>
							</div>
						</a>
					@endforeach
				</div>
			</div>
		</section>

		<!-- Lokasi -->
		<section class="tf-page-section tf-wrap">
			<div class="tf-visit">
				<div class="tf-pinmap wow fadeInUp" x-data="pinnedMap" data-lat="{{ $site::MAP_LAT }}" data-lng="{{ $site::MAP_LNG }}">
					<iframe x-ref="frame" src="https://www.google.com/maps?q={{ $site::MAP_LAT }},{{ $site::MAP_LNG }}&z=16&output=embed" title="{{ $p['visit']['mapTitle'] }}" loading="lazy" tabindex="-1" referrerpolicy="no-referrer-when-downgrade"></iframe>
					<div class="tf-pinmap-shield" @wheel="onWheel"></div>
					<a class="tf-pinmap-route" href="{{ $site::MAPS_DIRECTIONS_URL }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $p['visit']['routeLabel'] }} ({{ $t['aria']['newTab'] }})">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l19-9-9 19-2-8-8-2z"></path></svg>
						<span>{{ $p['visit']['route'] }}</span>
					</a>
					<div class="tf-pinmap-zoom">
						<button type="button" aria-label="{{ $p['visit']['zoomIn'] }}" :disabled="zoom >= 19" @click="zoomIn">+</button>
						<button type="button" aria-label="{{ $p['visit']['zoomOut'] }}" :disabled="zoom <= 12" @click="zoomOut">&minus;</button>
					</div>
				</div>
				<div class="tf-visit-card wow fadeInUp" data-wow-delay="0.06s">
					<p class="tf-eyebrow tf-eyebrow-gold">{{ $p['visit']['eyebrow'] }}</p>
					<h2>{{ $site->rich($p['visit']['title']) }}</h2>
					<dl>
						<div><dt>{{ $p['visit']['address'] }}</dt><dd>{{ $t['footer']['address'] }}</dd></div>
						<div><dt>{{ $p['visit']['phone'] }}</dt><dd><a href="{{ $site->phoneHref() }}">{{ $site->phoneDisplay() }}</a></dd></div>
						<div><dt>{{ $p['visit']['hours'] }}</dt><dd>@foreach ($t['footer']['hours'] as $row)<span>{{ $row }}</span>@endforeach</dd></div>
					</dl>
				</div>
			</div>
		</section>

		@include('store.partials.page-cta')
	</main>
@endsection
