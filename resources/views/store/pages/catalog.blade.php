@extends('store.layouts.app')

@php
	$categories = config('store_catalog.categories');
	$jsItems = $items->map(fn ($x) => \Illuminate\Support\Arr::only($x, ['sku', 'cat', 'type', 'name', 'price']))->values();
	$meta = fn ($x) => collect([$site->weightText($x), $site->shelfText($x)])->filter()->implode(' · ');
@endphp

@section('content')
	<script type="application/json" id="tf-catalog-data">{{ $site->json(['items' => $jsItems, 'categories' => $categories, 'words' => \Illuminate\Support\Arr::only($c, ['cats', 'types', 'showing', 'page'])]) }}</script>
	<main x-data="catalog">
		<section class="tf-wrap tf-cat-hero">
			<p class="tf-cat-pill wow fadeInUp">{{ $c['eyebrow'] }}</p>
			<h1 class="tf-h2 tf-cat-title text-anime-style-2" data-cursor="-opaque">{{ $site->rich($c['title']) }}</h1>
			<p class="tf-lead tf-cat-lead wow fadeInUp" data-wow-delay="0.1s">{{ $c['lead'] }}</p>
			<div class="tf-cat-find wow fadeInUp" data-wow-delay="0.15s">
				<form class="tf-cat-search" role="search" action="{{ $site->route('products') }}" @submit.prevent>
					<label>{{ $site->icon('search', 20) }}<input x-model="query" type="search" name="q" autocomplete="off" enterkeyhint="search" placeholder="{{ $c['searchPlaceholder'] }}" aria-label="{{ $c['searchLabel'] }}"></label>
					<button type="submit" aria-label="{{ $c['searchLabel'] }}">{{ $site->icon('search', 20) }}</button>
				</form>
				<a href="{{ $site->route('cart') }}" class="tf-cat-cart" aria-label="{{ $c['cartLabel'] }}" :aria-label="$store.cart.count ? @js($c['cartLabel']) + ', ' + $store.cart.count : @js($c['cartLabel'])"><img class="tf-bag" src="/images/Logo/icon/shopbag.webp" width="40" height="40" alt="" aria-hidden="true"><span x-ref="badge" class="tf-cat-cart-count" x-show="$store.cart.count" x-text="$store.cart.count" @if (! $cartCount) style="display: none" @endif>{{ $cartCount ?: '' }}</span></a>
			</div>
		</section>

		<section class="tf-wrap tf-cat-bar">
			<button type="button" class="tf-cat-toggle" aria-controls="tf-cat-filters" aria-expanded="false" :aria-expanded="filtersOpen" @click="toggleFilters">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"></path></svg>
				{{ $c['filter'] }}<template x-if="activeCount"><span x-text="activeCount"></span></template>
			</button>
			<div class="tf-cat-sort" role="group" aria-label="{{ $c['sort'] }}">
				<span aria-hidden="true">{{ $c['sort'] }}</span>
				@foreach ($c['sorts'] as $i => $label)
					<button type="button" aria-pressed="{{ $i === 0 ? 'true' : 'false' }}" :aria-pressed="sortIdx === {{ $i }}" @click="setSort({{ $i }})">{{ $label }}</button>
				@endforeach
			</div>
			<p class="tf-cat-count" role="status" x-text="showingText">{{ $site->fill($c['showing'], ['n' => min(12, $items->count()), 'total' => $items->count()]) }}</p>
		</section>

		<section x-ref="results" class="tf-wrap tf-cat-body">
			<aside id="tf-cat-filters" class="tf-cat-side" :class="filtersOpen ? 'is-open' : ''">
				<div class="tf-cat-side-head">
					<h2>{{ $c['filterTitle'] }}</h2>
					<button type="button" @click="reset">{{ $c['reset'] }}</button>
				</div>
				<template x-if="query"><p class="tf-cat-query">{{ $c['searching'] }} “<span x-text="query"></span>” <button type="button" aria-label="{{ $c['clearSearch'] }}" @click="clearQuery">×</button></p></template>
				<fieldset>
					<legend>{{ $c['catTitle'] }}</legend>
					<label><input type="radio" name="tf-cat" checked :checked="cat === 'all'" @change="setCat('all')"><span>{{ $c['allCats'] }}</span><i x-text="countFor('all')">{{ $items->count() }}</i></label>
					@foreach ($categories as $key)
						<label><input type="radio" name="tf-cat" :checked="cat === '{{ $key }}'" @change="setCat('{{ $key }}')"><span>{{ $c['cats'][$key] }}</span><i x-text="countFor('{{ $key }}')">{{ $items->where('cat', $key)->count() }}</i></label>
					@endforeach
				</fieldset>
				<template x-if="types.length">
					<fieldset>
						<legend>{{ $c['typeTitle'] }}</legend>
						<div class="tf-cat-chips">
							<button type="button" :aria-pressed="type === 'all'" @click="setType('all')">{{ $c['allTypes'] }}</button>
							<template x-for="key in types" :key="key"><button type="button" :aria-pressed="type === key" @click="setType(key)" x-text="typeLabel(key)"></button></template>
						</div>
					</fieldset>
				</template>
				<fieldset>
					<legend>{{ $c['priceTitle'] }}</legend>
					@foreach ($c['prices'] as $i => $label)
						<label><input type="radio" name="tf-price" @if ($i === 0) checked @endif :checked="priceIdx === {{ $i }}" @change="setPrice({{ $i }})"><span>{{ $label }}</span></label>
					@endforeach
				</fieldset>
			</aside>

			<div class="tf-cat-main">
				<div x-ref="grid" x-show="!isEmpty" class="tf-cat-grid">
					@foreach ($items as $i => $x)
						<article class="tf-cat-card" data-sku="{{ $x['sku'] }}" @if ($i >= 12) hidden @endif>
							<a href="{{ $x['path'] }}" class="tf-cat-link" aria-hidden="true" tabindex="-1">
								<figure>
									@if ($x['photos'])
										<img src="{{ $x['photos'][0]['src'] }}" alt="{{ $x['name'][$locale] }}" width="640" height="640" loading="lazy" decoding="async">
									@endif
									@if ($x['cat'])
										<span>{{ $c['cats'][$x['cat']] }}</span>
									@endif
								</figure>
							</a>
							<div class="tf-cat-card-body">
								<h3><a href="{{ $x['path'] }}">{{ $x['name'][$locale] }}</a></h3>
								<p>{{ $meta($x) }}</p>
								<div class="tf-cat-price"><strong>{{ $site->rupiah($x['price']) }}</strong>@if ($x['normal'] > $x['price'])<s>{{ $site->rupiah($x['normal']) }}</s>@endif</div>
								<button type="button" class="tf-btn tf-btn-small tf-btn-block" data-add-cart="{{ $x['sku'] }}">{{ $site->icon('shopping_bag', 16) }} {{ $c['cart'] }}</button>
							</div>
						</article>
					@endforeach
				</div>
				<template x-if="isEmpty">
					<div class="tf-cat-empty">
						<h2>{{ $c['emptyTitle'] }}</h2>
						<p>{{ $c['emptyText'] }}</p>
						<button type="button" class="tf-btn" @click="reset">{{ $c['reset'] }}</button>
					</div>
				</template>

				<template x-if="pageCount > 1">
					<nav class="tf-cat-pages" aria-label="{{ $c['pagination'] }}">
						<p x-text="pageText"></p>
						<div>
							<template x-for="n in pageNumbers" :key="n"><button type="button" :aria-current="page === n ? 'page' : null" :aria-label="pageLabel(n)" @click="goTo(n)" x-text="n"></button></template>
							<button type="button" class="tf-cat-next" :disabled="page === pageCount" @click="goNext">{{ $c['next'] }} →</button>
						</div>
					</nav>
				</template>
			</div>
		</section>

		@include('store.partials.trust')
	</main>
@endsection
