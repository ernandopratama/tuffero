@extends('store.layouts.app')

{{-- Halaman kategori (R-08): daftar produk satu kategori, dirender penuh di server (tanpa JS untuk isi). Kartu sama dengan katalog. --}}
@php
	$meta = fn ($x) => collect([$site->weightText($x), $site->shelfText($x)])->filter()->implode(' · ');
@endphp

@section('content')
	<main>
		<section class="tf-wrap tf-cat-hero">
			<nav class="tf-pd-crumbs tf-catpage-crumbs" aria-label="{{ $p['breadcrumb'] }}">
				<ol>
					<li><a href="/">{{ $p['home'] }}</a></li>
					<li><a href="{{ $site->route('products') }}">{{ $p['products'] }}</a></li>
					<li aria-current="page">{{ $name }}</li>
				</ol>
			</nav>
			<p class="tf-cat-pill">{{ $g['eyebrow'] }}</p>
			<h1 class="tf-h2 tf-cat-title">{{ $site->fill($g['h1'], ['name' => $name]) }}</h1>
			<p class="tf-lead tf-cat-lead">{{ $intro }}</p>
		</section>

		<section class="tf-wrap tf-catpage" aria-label="{{ $name }}">
			<div class="tf-cat-grid">
				@foreach ($items as $x)
					<article class="tf-cat-card" data-sku="{{ $x['sku'] }}">
						<a href="{{ $x['path'] }}" class="tf-cat-link" aria-hidden="true" tabindex="-1">
							<figure>
								@if ($x['photos'])
									<img src="{{ $x['photos'][0]['src'] }}" alt="{{ $x['name'][$locale] }}" width="640" height="640" loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" decoding="async">
								@endif
							</figure>
						</a>
						<div class="tf-cat-card-body">
							<h2><a href="{{ $x['path'] }}">{{ $x['name'][$locale] }}</a></h2>
							<p>{{ $meta($x) }}</p>
							<div class="tf-cat-price"><strong>{{ $site->rupiah($x['price']) }}</strong>@if ($x['normal'] > $x['price'])<s>{{ $site->rupiah($x['normal']) }}</s><em class="tf-off">-{{ $site->offPercent($x) }}%</em>@endif</div>
							@include('store.partials.cart-button', ['sku' => $x['sku'], 'name' => $x['name'][$locale]])
						</div>
					</article>
				@endforeach
			</div>
		</section>

		<nav class="tf-wrap tf-catpage-more" aria-label="{{ $g['moreTitle'] }}">
			<h2>{{ $g['moreTitle'] }}</h2>
			<ul class="tf-catlinks">
				@foreach ($others as $other)
					<li><a href="/kategori/{{ $other['slug'] }}">{{ $other['name'][$locale] }}</a></li>
				@endforeach
				<li><a href="{{ $site->route('products') }}">{{ $g['allProducts'] }}</a></li>
			</ul>
		</nav>

		@include('store.partials.trust')
	</main>
@endsection
