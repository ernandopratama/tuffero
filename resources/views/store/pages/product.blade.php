@extends('store.layouts.app')

@php
	$newTab = $t['aria']['newTab'];
	$name = $x['name'][$locale];
	$photos = $x['photos'];
	$saving = $x['normal'] > $x['price'] ? (int) round((1 - $x['price'] / $x['normal']) * 100) : 0;
	$priceNote = collect([$x['normal'] > $x['price'] ? $site->fill($p['priceNormal'], ['price' => $site->rupiah($x['normal'])]) : '', $p['priceNote']])->filter()->implode(' ');
	$rating = $reviews['rating'];
	$reviewCount = $reviews['count'];
	$decimal = str_replace('.', $locale === 'id' ? ',' : '.', (string) $rating);
	$pct = (($rating / 5) * 100).'%';
	$specs = [[$p['specs']['pack'], "{$x['pack']} g", null]];
	if ($x['cat']) { array_unshift($specs, [$p['specs']['cat'], $x['cat_name'][$locale], "{$site->route('products')}?kategori={$x['cat']}"]); }
	if ($x['shelf']) { $specs[] = [$p['specs']['shelf'], $site->shelfPlain($x), null]; }
	if ($x['net']) { $specs[] = [$p['specs']['net'], "{$x['net']} g", null]; }
	$specs[] = [$p['specShip'], $p['specShipValue'], null];
	$specs[] = [$p['specs']['sku'], $x['sku'], null];
	$waHref = 'https://wa.me/?text='.rawurlencode($name.' '.url($x['path']));
@endphp

@section('root') x-data="product" @endsection

@section('content')
	<script type="application/json" id="tf-product-data">{{ $site->json(['sku' => $x['sku'], 'path' => $x['path'], 'photos' => $photos, 'max' => $x['max_quantity'], 'words' => ['copied' => $p['copied']]]) }}</script>
	<main>
		<!-- Bagian atas: galeri dan pembelian -->
		<section class="tf-wrap tf-pd">
			<nav class="tf-pd-crumbs wow fadeInUp" aria-label="{{ $p['breadcrumb'] }}">
				<ol>
					<li><a href="/">{{ $p['home'] }}</a></li>
					<li><a href="{{ $site->route('products') }}">{{ $p['products'] }}</a></li>
					<li aria-current="page">{{ $name }}</li>
				</ol>
			</nav>

			<div class="tf-pd-grid">
				<div class="tf-pd-gallery wow fadeInUp" data-wow-delay="0.05s">
					<figure class="tf-pd-main">
						@if ($photos)
							<img src="{{ $photos[0]['src'] }}" :src="photoSrc" alt="{{ $photos[0]['alt'] }}" :alt="photoAlt" width="900" height="900" fetchpriority="high" decoding="async">
						@endif
					</figure>
					@if (count($photos) > 1)
						<ul class="tf-pd-thumbs" aria-label="{{ $p['photos'] }}">
							@foreach ($photos as $i => $ph)
								<li>
									<button type="button" aria-label="{{ $site->fill($p['photo'], ['n' => $i + 1, 'total' => count($photos)]) }}" @if ($i === 0) aria-current="true" @endif :aria-current="active === {{ $i }} ? 'true' : null" @click="setPhoto({{ $i }})">
										<img src="{{ $ph['src'] }}" alt="" width="240" height="240" decoding="async">
									</button>
								</li>
							@endforeach
						</ul>
					@endif
					<div class="tf-pd-share">
						<span>{{ $p['share'] }}</span>
						<a href="{{ $waHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $p['shareWa'] }} ({{ $newTab }})">
							<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path></svg>WhatsApp
						</a>
						<button type="button" @click="copyLink">
							<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>{{ $p['copyLink'] }}
						</button>
					</div>
				</div>

				<div class="tf-pd-info wow fadeInUp" data-wow-delay="0.1s">
					@if ($x['cat'])
						<span class="tf-pd-badge">{{ $x['cat_name'][$locale] }}</span>
					@endif
					@foreach ($x['badges'] as $badge)
						<span class="tf-pd-badge">{{ $badge }}</span>
					@endforeach
					<h1 class="tf-pd-title">{{ $name }}</h1>
					<a href="#ulasan" class="tf-pd-rating">
						<strong>{{ $decimal }}</strong>
						<span class="tf-stars-bar" style="--pct: {{ $pct }}" role="img" aria-label="{{ $decimal }} {{ $p['reviewsOutOf'] }}"><i>@for ($n = 0; $n < 5; $n++){{ $site->icon('star', 16) }}@endfor</i><b>@for ($n = 0; $n < 5; $n++){{ $site->icon('star', 16) }}@endfor</b></span>
						<span>{{ $site->fill($p['ratingCount'], ['n' => $reviewCount]) }}</span>
						<small>{{ $p['rating'] }}</small>
					</a>

					<div class="tf-pd-pricebox" role="group" aria-label="{{ $p['stickyPrice'] }}">
						<strong>{{ $site->rupiah($x['price']) }}</strong>
						@if ($x['normal'] > $x['price'])
							<s>{{ $site->rupiah($x['normal']) }}</s>
						@endif
						@if ($saving)
							<em>{{ $site->fill($p['save'], ['pct' => $saving]) }}</em>
						@endif
					</div>
					<p class="tf-pd-note">{{ $priceNote }}</p>

					<div class="tf-pd-rows">
						<div class="tf-pd-row">
							<span>{{ $p['shipLabel'] }}</span>
							<div class="tf-pd-ship">{{ $site->icon('truck', 22) }}<div><b>{{ $p['shipShort'] }}</b><small>{{ $p['shipSub'] }}</small></div></div>
						</div>
						<div class="tf-pd-row">
							<label id="tf-qty-label" for="tf-qty">{{ $p['qty'] }}</label>
							<div class="tf-pd-stepper">
								<button type="button" aria-label="{{ $p['qtyDec'] }}" disabled :disabled="qty <= 1" @click="dec"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path></svg></button>
								<input id="tf-qty" type="text" inputmode="numeric" autocomplete="off" value="1" :value="qtyText" @input="onQtyInput" @blur="settleQty">
								<button type="button" aria-label="{{ $p['qtyInc'] }}" :disabled="atMax" @click="inc"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg></button>
							</div>
						</div>
						<p @class(['tf-pd-stock', 'is-out' => ! $x['in_stock']]) role="status">{{ $x['in_stock'] ? $p['stockIn'] : $p['stockOut'] }} · {{ $site->fill($p['maxQty'], ['n' => $x['max_quantity']]) }}</p>
					</div>

					<div x-ref="cta" class="tf-pd-cta">
						<button type="button" class="tf-pd-add" @click="addCurrent">{{ $site->icon('shopping_bag', 20) }}{{ $p['addShort'] }}</button>
						<button type="button" class="tf-btn" @click="buyNow">{{ $p['buyShort'] }}</button>
					</div>
				</div>
			</div>
		</section>

		<!-- Spesifikasi, deskripsi, ulasan, dan pilihan toko -->
		<section class="tf-wrap tf-pd-cols">
			<div class="tf-pd-col-main">
				<article class="tf-pd-card">
					<h2>{{ $p['specsTitle'] }}</h2>
					<dl class="tf-pd-specs">
						@foreach ($specs as [$label, $value, $href])
							<div><dt>{{ $label }}</dt><dd>@if ($href)<a href="{{ $href }}">{{ $value }}</a>@else{{ $value }}@endif</dd></div>
						@endforeach
					</dl>
				</article>

				<article class="tf-pd-card">
					<h2>{{ $p['descTitle'] }}</h2>
					<p class="tf-pd-text">{{ $description }}</p>
				</article>

				<article id="ulasan" class="tf-pd-card">
					<h2>{{ $p['reviewsTitle'] }}</h2>
					<div class="tf-pd-score">
						<div class="tf-pd-score-num"><strong>{{ $decimal }}</strong><span>{{ $p['reviewsOutOf'] }}</span></div>
						<div>
							<span class="tf-stars-bar is-lg" style="--pct: {{ $pct }}" role="img" aria-label="{{ $decimal }} {{ $p['reviewsOutOf'] }}"><i>@for ($n = 0; $n < 5; $n++){{ $site->icon('star', 22) }}@endfor</i><b>@for ($n = 0; $n < 5; $n++){{ $site->icon('star', 22) }}@endfor</b></span>
							<p>{{ $site->fill($p['ratingCount'], ['n' => $reviewCount]) }} · {{ $p['rating'] }}</p>
						</div>
					</div>
					<p class="tf-pd-source">{{ $p['reviewsSource'] }}</p>
					<ul class="tf-pd-reviews">
						@foreach ($reviews['reviews'] as $r)
							<li>
								<span class="tf-pd-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($r['author_name'], 0, 1)) }}</span>
								<div><strong>{{ $r['author_name'] }}</strong><p lang="id">{{ $r['body'] }}</p></div>
							</li>
						@endforeach
					</ul>
					<a href="{{ $site::MAPS_URL }}" target="_blank" rel="noopener noreferrer" class="tf-more" aria-label="{{ $p['reviewsOpen'] }} ({{ $newTab }})">{{ $p['reviewsOpen'] }} {{ $site->icon('north_east', 12) }}</a>
				</article>
			</div>

			<aside class="tf-pd-picks">
				<h2>{{ $p['picksTitle'] }}</h2>
				<ul>
					@foreach ($picks as $y)
						<li>
							<a href="{{ $y['path'] }}">
								@if ($y['photos'])
									<img src="{{ $y['photos'][0]['src'] }}" alt="" width="240" height="240" loading="lazy" decoding="async">
								@endif
								<span><b>{{ $y['name'][$locale] }}</b><strong>{{ $site->rupiah($y['price']) }}</strong></span>
							</a>
						</li>
					@endforeach
				</ul>
			</aside>
		</section>

		<!-- Mungkin Anda juga suka -->
		<section class="tf-wrap tf-pd-also">
			<h2>{{ $p['alsoTitle'] }}</h2>
			<ul class="tf-pc-grid">
				@foreach ($also as $y)
					<li>
						<a href="{{ $y['path'] }}" class="tf-pc">
							<figure>@if ($y['photos'])<img src="{{ $y['photos'][0]['src'] }}" alt="" width="240" height="240" loading="lazy" decoding="async">@endif</figure>
							<h3>{{ $y['name'][$locale] }}</h3>
							<p class="tf-pc-price"><strong>{{ $site->rupiah($y['price']) }}</strong>@if ($y['normal'] > $y['price'])<s>{{ $site->rupiah($y['normal']) }}</s><em class="tf-off">-{{ $site->offPercent($y) }}%</em>@endif</p>
							<p class="tf-pc-meta"><span>{{ $site->weightText($y) }}</span>@if ($y['shelf'])<span>{{ $site->shelfText($y) }}</span>@endif</p>
						</a>
					</li>
				@endforeach
			</ul>
		</section>

		@include('store.partials.trust')
	</main>

	<div class="tf-pd-sticky" :class="stickyOn ? 'is-on' : ''" inert :inert="!stickyOn">
		<div><span>{{ $p['stickyPrice'] }}</span><strong>{{ $site->rupiah($x['price']) }}</strong></div>
		<button type="button" class="tf-btn" @click="addCurrent">{{ $p['addShort'] }}</button>
	</div>
@endsection
