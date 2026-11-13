@extends('store.layouts.app')

@php
	$img = fn ($name) => "/images/landing/{$name}";
	// Versi kecil 640 px (thumb/) bukan berkas asli; tampil paling besar 300 px.
	$thumb = fn ($name) => "/images/Produk/thumb/{$name} 1.webp";
	$aboutIcons = ['nastar', 'pineapple', 'redeem'];
	$whyIcons = ['pineapple', 'celebration', 'mknindo'];
	$featureIcons = ['nastar', 'macaron', 'pia', 'redeem'];
	// Langkah "Cara pesan": tangkapan layar halaman Produk dan Checkout (tanpa data pribadi), lalu foto kurir menyerahkan paket.
	$stepPhotos = ['/images/landing/cara-katalog.webp', '/images/landing/cara-checkout.webp', '/images/landing/cara-terima.webp'];
	$galleryPhotos = ['galeri-1.webp', 'galeri-2.webp', 'galeri-3.webp', 'galeri-4.webp', 'galeri-5.webp', 'galeri-6.webp'];
	$statIcons = [['timer', 40, $site->years()], ['star', 36, $site::RATING], ['semua', 36, $productTotal]];
	$delay = fn ($i) => ($i * 0.06).'s';
@endphp

@section('content')
	<!-- Filter SVG untuk tombol TikTok (tepi bergetar seperti digambar tangan) -->
	<svg class="tf-filters" width="0" height="0" aria-hidden="true" focusable="false">
		<filter id="handDrawnNoise"><feTurbulence result="noise" numOctaves="2" baseFrequency="0.1" type="fractalNoise"></feTurbulence><feDisplacementMap yChannelSelector="G" xChannelSelector="R" scale="3" in2="noise" in="SourceGraphic"></feDisplacementMap></filter>
		<filter id="handDrawnNoise2"><feTurbulence result="noise" numOctaves="2" baseFrequency="0.1" seed="1010" type="fractalNoise"></feTurbulence><feDisplacementMap yChannelSelector="G" xChannelSelector="R" scale="3" in2="noise" in="SourceGraphic"></feDisplacementMap></filter>
		<filter id="handDrawnNoiset"><feTurbulence result="noise" numOctaves="2" baseFrequency="0.1" type="fractalNoise"></feTurbulence><feDisplacementMap yChannelSelector="G" xChannelSelector="R" scale="6" in2="noise" in="SourceGraphic"></feDisplacementMap></filter>
		<filter id="handDrawnNoiset2"><feTurbulence result="noise" numOctaves="2" baseFrequency="0.1" seed="1010" type="fractalNoise"></feTurbulence><feDisplacementMap yChannelSelector="G" xChannelSelector="R" scale="6" in2="noise" in="SourceGraphic"></feDisplacementMap></filter>
	</svg>

	<main>
		<!-- Hero Section Start -->
		<section class="tf-hero tf-wrap">
			<div class="tf-hero-box">
				<img class="tf-hero-photo" src="/images/Tuffero Bakery Treats Showcase.webp" {{ $site->imageSize('/images/Tuffero Bakery Treats Showcase.webp') }} alt="" fetchpriority="high">
				<div class="tf-hero-video" aria-hidden="true"><video class="is-on" src="/videos/HeroVideo%20(1).mp4" muted playsinline tabindex="-1" preload="auto"></video><video src="/videos/HeroVideo%20(2).mp4" muted playsinline tabindex="-1" preload="metadata"></video></div>
				<div class="tf-hero-shade"></div>
				@php($w = $t['hero']['welcome'])
				<div @class(['tf-hero-content', 'is-welcome' => $welcome])>
					@if ($welcome)
						{{-- Pelanggan yang sudah masuk: sapaan personal, pesanan aktif, dan pintasan akun. --}}
						<p class="tf-hero-hello wow fadeInUp"><span class="tf-hero-avatar" aria-hidden="true">{{ $welcome['initials'] }}</span>{{ $welcome['greeting'] }}</p>
						<h1 class="tf-h1 text-anime-style-2" data-cursor="-opaque">{{ $w['title'] }} <br class="tf-br"><em>{{ $welcome['name'] }}</em></h1>
						<p class="tf-hero-text wow fadeInUp" data-wow-delay="0.1s">{{ $w['text'] }}</p>
						@if ($welcome['order'])
							<a href="/pesanan/{{ $welcome['order']->public_ref }}" class="tf-hero-order wow fadeInUp" data-wow-delay="0.12s">
								<span class="tf-hero-order-ico">@include('store.partials.account-icon', ['name' => 'order'])</span>
								<span class="tf-hero-order-main"><small>{{ $w['order'] }}</small><strong>{{ $welcome['order']->order_number }}</strong></span>
								<span class="tf-hero-order-pill">{{ $pg['order']['status'][$welcome['order']->status][0] ?? $welcome['order']->status }}</span>
								<span class="tf-hero-order-go"><span>{{ $w['orderLink'] }}</span>@include('store.partials.account-icon', ['name' => 'arrow'])</span>
							</a>
						@endif
					@else
						<h1 class="tf-h1 text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['hero']['title']) }}</h1>
						<p class="tf-hero-text wow fadeInUp" data-wow-delay="0.1s">{{ $t['hero']['text'] }}</p>
					@endif
					<div class="tf-hero-actions wow fadeInUp" data-wow-delay="0.15s">
						<a href="{{ $site->route('products') }}" class="tf-btn tf-btn-light tf-btn-hero tf-fly-btn">
							<span class="tf-fly"><span class="tf-fly-in"><span class="tf-icon tf-icon-cart" aria-hidden="true"></span></span></span>
							<span class="tf-btn-label">{{ $t['hero']['shop'] }}</span>
						</a>
						<a href="{{ $site::TIKTOK_URL }}" class="tf-tiktok" target="_blank" rel="noopener noreferrer" aria-label="{{ $t['hero']['tiktok'] }} ({{ $t['aria']['newTab'] }})">
							<svg class="tf-tiktok-cosm" viewBox="0 0 256 256" aria-hidden="true"><path d="M243.07324,157.43945c-1.2334-1.47949-23.18847-27.34619-60.46972-41.05859-1.67579-17.97412-8.25293-34.36328-18.93653-46.87158C149.41309,52.8208,128.78027,44,104,44,54.51074,44,22.10059,88.57715,20.74512,90.4751a3.99987,3.99987,0,0,0,6.50781,4.65234C27.5625,94.6958,58.68359,52,104,52c22.36816,0,40.89648,7.85107,53.584,22.70508,8.915,10.437,14.65625,23.9541,16.65528,38.894A133.54185,133.54185,0,0,0,136,108c-25.10742,0-46.09473,6.48486-60.69434,18.75391-12.65234,10.63379-19.91015,25.39355-19.91015,40.49463a43.61545,43.61545,0,0,0,12.69336,31.21923C76.98438,207.3208,89.40234,212,104,212c23.98047,0,44.37305-9.4668,58.97461-27.37744,12.74512-15.6333,20.05566-37.145,20.05566-59.01953,0-.1128-.001-.22559-.001-.33838,33.62988,13.48486,53.62207,36.96631,53.89746,37.2959a4.00015,4.00015,0,0,0,6.14648-5.1211ZM104,204c-27.89746,0-40.60449-19.05078-40.60449-36.75146C63.39551,142.56592,86.11621,116,136,116a124.37834,124.37834,0,0,1,38.97266,6.32617q.05712,1.63038.05761,3.27686C175.03027,177.07129,139.29785,204,104,204Z"></path></svg>
							<svg class="tf-tiktok-hl" viewBox="0 0 144.75738 77.18431" preserveAspectRatio="none" aria-hidden="true"><g transform="translate(-171.52826,-126.11624)"><g fill="none" stroke-width="17" stroke-linecap="round" stroke-miterlimit="10"><path d="M180.02826,169.45123c0,0 12.65228,-25.55115 24.2441,-25.66863c6.39271,-0.06479 -5.89143,46.12943 4.90937,50.63857c10.22345,4.2681 24.14292,-52.38336 37.86455,-59.80493c3.31715,-1.79413 -5.35094,45.88889 -0.78872,58.34589c5.19371,14.18125 33.36934,-58.38221 36.43049,-56.91633c4.67078,2.23667 -0.06338,44.42744 5.22574,47.53647c6.04041,3.55065 19.87185,-20.77286 19.87185,-20.77286"></path></g></g></svg>
							<span>{{ $t['hero']['tiktok'] }}</span>
						</a>
					</div>
					@if ($welcome)
						<ul class="tf-hero-list is-links wow fadeInUp" data-wow-delay="0.2s">
							@foreach (['orders' => 'order', 'myVouchers' => 'voucher', 'addresses' => 'address'] as $route => $icon)
								<li><a href="{{ $site->route($route) }}">@include('store.partials.account-icon', ['name' => $icon]){{ $w['links'][$route] }}</a></li>
							@endforeach
						</ul>
					@else
						<ul class="tf-hero-list wow fadeInUp" data-wow-delay="0.2s">
							@foreach ($t['hero']['list'] as $item)
								<li>{{ $item }}</li>
							@endforeach
						</ul>
					@endif
				</div>
			</div>

			<div class="tf-hero-cards">
				<article class="tf-card-video image-anime wow fadeInUp">
					<img src="{{ $thumb('Nastar Classic') }}" {{ $site->imageSize($thumb('Nastar Classic')) }} alt="{{ $t['hero']['videoAlt'] }}">
					<div class="tf-card-video-shade"></div>
					<a href="/videos/HeroVideo%20(2).mp4" class="tf-pill popup-video" data-cursor-text="Play">{{ $t['hero']['videoLink'] }} {{ $site->icon('north_east', 12) }}</a>
					<div class="tf-card-video-text">
						<p>{{ $t['hero']['videoTag'] }}</p>
						<h2>{{ $t['hero']['videoTitle'] }}</h2>
					</div>
				</article>
				<article class="tf-card-rate wow fadeInUp" data-wow-delay="0.06s">
					<img class="tf-card-rate-bg" src="{{ $img('galeri-2.webp') }}" {{ $site->imageSize($img('galeri-2.webp')) }} alt="" loading="lazy" decoding="async">
					<div class="tf-card-video-shade"></div>
					<div class="tf-card-rate-quotes">
						@foreach ($t['hero']['reviews'] as $i => [$author, $review])
							<figure class="tf-card-rate-quote" style="--i: {{ $i }}"><blockquote>{{ $review }}</blockquote><figcaption>{{ $author }}</figcaption></figure>
						@endforeach
					</div>
					<div class="tf-rate-box">
						<div class="tf-rate-head">
							<img src="/images/icon-google.svg" alt="" width="32" height="32">
							<div>
								<p>{{ $t['hero']['ratingLabel'] }}</p>
								<p class="tf-rate-stars"><span class="tf-stars" aria-hidden="true">@for ($s = 0; $s < 5; $s++){{ $site->icon('star', 13) }}@endfor</span><strong>{{ $google['rating'] }}</strong></p>
							</div>
						</div>
						<div class="tf-rate-foot">
							<div class="tf-faces" aria-hidden="true">
								@foreach (array_column($t['hero']['reviews'], 0) as $name)
									<span>{{ \Illuminate\Support\Str::of($name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') }}</span>
								@endforeach
							</div>
							<span class="tf-rate-count">{{ __('store.landing.hero.reviewCount', ['count' => $google['count']]) }}</span>
						</div>
					</div>
				</article>
				<article class="tf-card-hours wow fadeInUp" data-wow-delay="0.12s">
					<div class="tf-card-hours-head">
						<h2>{{ $t['hero']['factsTitle'] }}</h2>
						{{ $site->icon('alarm', 20) }}
					</div>
					<ul>
						@foreach ($t['hero']['facts'] as $i => [$label, $value])
							<li><span>{{ $label }}</span>@if ($i === 2)<strong>{{ $value }}</strong>@else<span>{{ $value }}</span>@endif</li>
						@endforeach
					</ul>
					<a href="{{ $site->route('products') }}" class="tf-btn tf-btn-small tf-btn-block">{{ $t['hero']['start'] }}</a>
				</article>
			</div>
		</section>
		<!-- Hero Section End -->

		<!-- About Us Section Start -->
		<section id="about" class="tf-about tf-line">
			<div class="tf-wrap">
				<div class="tf-about-top">
					<div class="tf-about-copy">
						<p class="tf-eyebrow wow fadeInUp">{{ $t['about']['eyebrow'] }}</p>
						<h2 class="tf-h2 tf-h2-spaced text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['about']['title']) }}</h2>
						<p class="tf-lead wow fadeInUp">{{ $t['about']['p1'] }}</p>
						<p class="tf-lead wow fadeInUp" data-wow-delay="0.06s">{{ $t['about']['p2'] }}</p>
						<ul class="tf-checks wow fadeInUp" data-wow-delay="0.1s">
							@foreach ($t['about']['checks'] as $item)
								<li><span>{{ $site->icon('check', 14) }}</span>{{ $item }}</li>
							@endforeach
						</ul>
						<a href="{{ $site->route('about') }}" class="tf-btn tf-btn-medium wow fadeInUp" data-wow-delay="0.14s">{{ $t['about']['more'] }} {{ $site->icon('north_east', 12) }}</a>
					</div>
					<div class="tf-about-images">
						<figure class="image-anime wow fadeInUp"><img loading="lazy" decoding="async" src="{{ $img('tentang-1.webp') }}" {{ $site->imageSize($img('tentang-1.webp')) }} alt="{{ $t['about']['photo1'] }}"></figure>
						<figure class="image-anime wow fadeInUp" data-wow-delay="0.08s"><img loading="lazy" decoding="async" src="{{ $img('tentang-2.webp') }}" {{ $site->imageSize($img('tentang-2.webp')) }} alt="{{ $t['about']['photo2'] }}"></figure>
						<div class="tf-years"><strong><span class="counter">{{ $site->years() }}</span></strong><span>{{ $site->rich($t['about']['years']) }}</span></div>
					</div>
				</div>
				<ul class="tf-about-list">
					@foreach ($t['about']['items'] as $i => [$title, $text])
						<li class="wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
							<span class="tf-square">{{ $site->icon($aboutIcons[$i], 24) }}</span>
							<div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
						</li>
					@endforeach
				</ul>
			</div>
		</section>
		<!-- About Us Section End -->

		<!-- Our Services Section Start -->
		<section id="services" class="tf-services">
			<div class="tf-wrap">
				<div class="tf-services-head">
					<div>
						<p class="tf-eyebrow wow fadeInUp">{{ $t['services']['eyebrow'] }}</p>
						<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['services']['title']) }}</h2>
					</div>
					<p class="tf-small-lead wow fadeInUp">{{ $t['services']['lead'] }}</p>
				</div>
				<div class="tf-service-grid">
					@foreach ($t['services']['cards'] as $i => [$title, $text])
						<article @class(['tf-service', 'wow', 'fadeInUp', 'is-active' => $i === 2]) data-wow-delay="{{ $delay($i) }}">
							<span class="tf-service-num">0{{ $i + 1 }}.</span>
							<h3>{{ $site->rich($title) }}</h3>
							<p>{{ $text }}</p>
							<a href="{{ $site->route('services') }}#{{ $site::SERVICE_IDS[$i] }}" class="tf-more">{{ $t['services']['read'] }} {{ $site->icon('north_east', 12) }}</a>
						</article>
					@endforeach
				</div>
				<a href="{{ $site->route('products') }}" class="tf-free wow fadeInUp"><span class="tf-free-tag">{{ $t['services']['free'] }}</span><span class="tf-free-body"><strong>{{ $t['services']['freeTitle'] }}</strong><small>{{ $t['services']['freeSub'] }}</small></span><span class="tf-free-go" aria-hidden="true">{{ $site->icon('north_east', 20) }}</span></a>
			</div>
		</section>
		<!-- Our Services Section End -->

		<!-- Why Choose Us Section Start -->
		<section class="tf-why tf-line">
			<div class="tf-wrap tf-why-grid">
				<div class="tf-why-copy">
					<p class="tf-eyebrow wow fadeInUp">{{ $t['why']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-spaced text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['why']['title']) }}</h2>
					<p class="tf-small-lead wow fadeInUp">{{ $t['why']['lead'] }}</p>
					<ul class="tf-why-list">
						@foreach ($t['why']['items'] as $i => [$title, $text])
							<li class="wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
								<span class="tf-round">{{ $site->icon($whyIcons[$i], 18) }}</span>
								<div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
							</li>
						@endforeach
					</ul>
				</div>
				<div class="tf-why-images">
					<div class="tf-why-col-a">
						<figure class="image-anime wow fadeInUp"><img loading="lazy" decoding="async" src="{{ $img('kenapa-1.webp') }}" {{ $site->imageSize($img('kenapa-1.webp')) }} alt="{{ $t['why']['photo1'] }}"></figure>
						<a href="{{ $site->route('contact') }}" class="tf-help wow fadeInUp" data-wow-delay="0.06s"><em>{{ $t['why']['helpA'] }}</em><strong>{{ $t['why']['helpB'] }}</strong></a>
					</div>
					<div class="tf-why-col-b">
						<div class="tf-trusted wow fadeInUp" data-wow-delay="0.08s">
							<p>{{ $t['why']['trusted'] }}</p>
							<div class="tf-dots"><span></span><span></span><span class="is-dark"></span><span class="is-dark"></span></div>
						</div>
						<figure class="image-anime wow fadeInUp" data-wow-delay="0.12s"><img loading="lazy" decoding="async" src="{{ $img('kenapa-2.webp') }}" {{ $site->imageSize($img('kenapa-2.webp')) }} alt="{{ $t['why']['photo2'] }}"></figure>
					</div>
				</div>
			</div>
		</section>
		<!-- Why Choose Us Section End -->

		<!-- Our Features Section Start -->
		<section class="tf-features">
			<div class="tf-wrap tf-features-grid">
				<div class="tf-features-card wow fadeInUp">
					<p class="tf-eyebrow tf-eyebrow-gold">{{ $t['features']['eyebrow'] }}</p>
					<h2 class="tf-h3 text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['features']['title']) }}</h2>
					<p class="tf-small-lead">{{ $t['features']['lead'] }}</p>
					<a href="#" class="tf-btn tf-btn-small">{{ $t['features']['more'] }} {{ $site->icon('north_east', 12) }}</a>
				</div>
				<div class="tf-feature-list">
					@foreach ($t['features']['items'] as $i => [$title, $text])
						<article class="tf-feature wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
							<span class="tf-round tf-round-lg">{{ $site->icon($featureIcons[$i], 24) }}</span>
							<h3>{{ $title }}</h3>
							<p>{{ $text }}</p>
						</article>
					@endforeach
				</div>
			</div>
		</section>
		<!-- Our Features Section End -->

		<!-- Our Products Section Start -->
		<section id="products" class="tf-products tf-line">
			<div class="tf-wrap">
				<div class="tf-head">
					<p class="tf-eyebrow wow fadeInUp">{{ $t['products']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['products']['title']) }}</h2>
				</div>
				@if ($featured->isNotEmpty())
					<div class="tf-product-grid">
						@foreach ($featured as $i => $row)
							<article class="tf-product wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
								<figure class="image-anime"><img loading="lazy" decoding="async" src="{{ $thumb($row['photo']) }}" {{ $site->imageSize($thumb($row['photo'])) }} alt="{{ $row['item']['name'][$locale] }}"><span class="tf-tag">{{ $row['label'] }}</span></figure>
								<span class="tf-product-num">0{{ $i + 1 }}.</span>
								<h3>{{ $row['item']['name'][$locale] }}</h3>
								<p class="tf-rate">{{ collect([$site->weightText($row['item']), $site->shelfText($row['item'])])->filter()->implode(' · ') }}</p>
								<p class="tf-price">{{ $site->rupiah($row['item']['price']) }}@if ($row['item']['normal'] > $row['item']['price']) <s>{{ $site->rupiah($row['item']['normal']) }}</s> <em class="tf-off">-{{ $site->offPercent($row['item']) }}%</em>@endif</p>
								<button type="button" class="tf-cart" data-add-cart="{{ $row['item']['sku'] }}"><span>+</span> {{ $t['products']['cart'] }}</button>
							</article>
						@endforeach
					</div>
				@endif
				<p class="tf-trail wow fadeInUp">{{ $t['products']['trail'] }} <a href="{{ $site->route('products') }}">{{ $t['products']['trailLink'] }}</a></p>
			</div>
		</section>
		<!-- Our Products Section End -->

		<!-- How It Work Section Start -->
		<section class="tf-how">
			<div class="tf-wrap">
				<div class="tf-head tf-head-how">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $t['how']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['how']['title']) }}</h2>
				</div>
				<div class="tf-how-grid">
					<div class="tf-how-card wow fadeInUp">
						<h3>{{ $t['how']['cardTitle'] }}</h3>
						<p>{{ $t['how']['cardText'] }}</p>
						<ul>@foreach ($t['how']['cardList'] as $item)<li>{{ $item }}</li>@endforeach</ul>
						<a href="{{ $site->route('contact') }}" class="tf-btn tf-btn-small tf-btn-block">{{ $t['how']['contact'] }} {{ $site->icon('north_east', 12) }}</a>
					</div>
					@foreach ($t['how']['steps'] as $i => [$title, $text])
						<article class="tf-step image-anime wow fadeInUp" data-wow-delay="{{ $delay($i + 1) }}">
							<img loading="lazy" decoding="async" src="{{ $stepPhotos[$i] }}" {{ $site->imageSize($stepPhotos[$i]) }} alt="">
							<div><span>0{{ $i + 1 }}</span><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
						</article>
					@endforeach
				</div>
			</div>
		</section>
		<!-- How It Work Section End -->

		<!-- Our Special Offers Section Start -->
		<section id="offers" class="tf-offers">
			<div class="tf-wrap">
				<div class="tf-head tf-head-how">
					<p class="tf-eyebrow wow fadeInUp">{{ $t['offers']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['offers']['title']) }}</h2>
				</div>
				<div class="tf-offer-grid">
					@foreach ($offerColumns as $side => $column)
						<div @class(['tf-offer-col', $side === 1 ? 'is-right' : 'is-left'])>
							@foreach ($column as $i => $row)
								<article class="tf-offer wow fadeInUp" data-wow-delay="{{ $delay($i) }}">
									<figure class="image-anime"><img loading="lazy" decoding="async" src="{{ $thumb($row['photo']) }}" {{ $site->imageSize($thumb($row['photo'])) }} alt="{{ $row['item']['name'][$locale] }}"></figure>
									<div class="tf-offer-body">
										<h3>{{ $row['item']['name'][$locale] }}</h3>
										<p>{{ $row['label'] }}</p>
										<div><strong>{{ $site->rupiah($row['item']['price']) }}</strong><span class="tf-badge">{{ $site->shelfText($row['item']) ?: $site->weightText($row['item']) }}</span></div>
									</div>
									<button type="button" class="tf-plus" aria-label="{{ $site->fill($t['aria']['addToCart'], ['name' => $row['item']['name'][$locale]]) }}" data-add-cart="{{ $row['item']['sku'] }}">+</button>
								</article>
							@endforeach
						</div>
					@endforeach
					<div class="tf-best wow fadeInUp">
						<h3>{{ $t['offers']['bestTitle'] }}</h3>
						<p>{{ $t['offers']['bestText'] }}</p>
						<a href="#" class="tf-more">{{ $t['offers']['bestLink'] }} {{ $site->icon('north_east', 12) }}</a>
						<figure class="image-anime"><img loading="lazy" decoding="async" src="{{ $thumb('Nastar Cookies') }}" {{ $site->imageSize($thumb('Nastar Cookies')) }} alt="{{ $t['offers']['bestAlt'] }}"></figure>
						<span>{{ $t['offers']['bestTag'] }}</span>
					</div>
				</div>
			</div>
		</section>
		<!-- Our Special Offers Section End -->

		<!-- Our Gallery Section Start -->
		<section id="gallery" class="tf-gallery tf-line">
			<div class="tf-wrap">
				<div class="tf-head tf-head-gallery">
					<p class="tf-eyebrow wow fadeInUp">{{ $t['gallery']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['gallery']['title']) }}</h2>
				</div>
				<div class="tf-slider gallery-slider gallery-items wow fadeInUp">
					<div class="swiper">
						<div class="swiper-wrapper">
							@for ($n = 0; $n < 8; $n++)
								<div class="swiper-slide">
									<a href="{{ $img($galleryPhotos[$n % 6]) }}" class="photo-gallery" data-cursor-text="{{ $t['gallery']['view'] }}">
										<figure class="image-anime"><img loading="lazy" decoding="async" src="{{ $img($galleryPhotos[$n % 6]) }}" {{ $site->imageSize($img($galleryPhotos[$n % 6])) }} alt="{{ $t['gallery']['alts'][$n % 6] }}"></figure>
									</a>
								</div>
							@endfor
						</div>
					</div>
					<button type="button" class="gallery-button-prev tf-arrow is-prev" aria-label="{{ $t['aria']['prev'] }}">←</button>
					<button type="button" class="gallery-button-next tf-arrow is-next" aria-label="{{ $t['aria']['next'] }}">→</button>
				</div>
			</div>
		</section>
		<!-- Our Gallery Section End -->

		<!-- Our Testimonial Section Start -->
		<section class="tf-testimonials">
			<div class="tf-wrap tf-testimonials-grid">
				<div class="tf-testimonials-copy">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $t['testimonials']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['testimonials']['title']) }}</h2>
					<blockquote class="wow fadeInUp">
						<p>{{ $t['testimonials']['quote'] }}</p>
						<footer><img src="/images/Logo/Tuffero Icon - NoBckgrnd.webp" alt="" width="40" height="40"><div><strong>{{ $t['testimonials']['name'] }}</strong><span>{{ $t['testimonials']['role'] }}</span></div></footer>
					</blockquote>
					<ul class="tf-stats wow fadeInUp" data-wow-delay="0.06s">
						@foreach ($statIcons as $i => [$icon, $size, $value])
							<li>
								<div>{{ $site->icon($icon, $size) }}<strong><span @class(['counter' => is_int($value)])>{{ $value }}</span></strong></div>
								<span>{{ $t['testimonials']['stats'][$i] }}</span>
							</li>
						@endforeach
					</ul>
				</div>
				<div class="tf-host image-anime wow fadeInUp">
					<img loading="lazy" decoding="async" src="{{ $img('testimoni.webp') }}" {{ $site->imageSize($img('testimoni.webp')) }} alt="{{ $t['testimonials']['hostAlt'] }}">
					<div class="tf-host-card"><p>{{ $t['testimonials']['hostText'] }}</p><a href="{{ $site->phoneHref() }}">{{ $site->phoneDisplay() }}</a></div>
				</div>
			</div>
		</section>
		<!-- Our Testimonial Section End -->

		<!-- Our Blog Section Start -->
		<section class="tf-blog tf-line">
			<div class="tf-head tf-wrap">
				<p class="tf-eyebrow wow fadeInUp">{{ $t['blog']['eyebrow'] }}</p>
				<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['blog']['title']) }}</h2>
				<p class="tf-blog-text wow fadeInUp">{{ $t['blog']['text'] }}</p>
			</div>
		</section>
		<!-- Our Blog Section End -->

		<!-- Trust Section Start -->
		@include('store.partials.trust')
		<!-- Trust Section End -->
	</main>
	@include('store.partials.news-popup', ['news' => $newsPopup])
@endsection
