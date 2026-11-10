@extends('store.layouts.app')

@php
	$when = fn ($date) => $date->copy()->locale(app()->getLocale())->translatedFormat('j M Y, H:i');
	$target = $live ? $campaign->ends_at : $campaign->starts_at;
@endphp

@section('content')
	<script type="application/json" id="tf-vouchers-data">{{ $site->json([...$c['claim'], 'customer' => $storeUser && ! $storeRole, 'signIn' => $site->route('account'), 'campaign' => $campaign->slug]) }}</script>
	<script type="application/json" id="tf-promo-data">{{ $site->json(\Illuminate\Support\Arr::only($c, ['days', 'ended'])) }}</script>
	<main class="tf-wrap tf-shop tf-campaign">
		<a href="{{ $site->route('promos') }}" class="tf-promo-back">@include('store.partials.account-icon', ['name' => 'back']){{ $c['title'] }}</a>
		<header class="tf-promo-head">
			@if ($campaign->image_path)
				<img class="tf-promo-banner" src="/storage/{{ $campaign->image_path }}" alt="{{ $campaign->title }}" width="1200" height="400" decoding="async">
			@endif
			<div class="tf-promo-head-body">
				<p class="tf-campaign-badges">
					@if ($campaign->is_flash)<em class="tf-campaign-flash">@include('store.partials.account-icon', ['name' => 'flash']){{ $c['flash'] }}</em>@endif
					<em @class(['tf-campaign-state', 'is-live' => $live])>{{ $live ? $c['live'] : $c['upcoming'] }}</em>
				</p>
				<h1 class="tf-promo-title">{{ $campaign->title }}</h1>
				@if ($campaign->description)<p class="tf-promo-desc">{{ $campaign->description }}</p>@endif
				<div class="tf-promo-meta">
					<p class="tf-promo-dates">@include('store.partials.account-icon', ['name' => 'calendar']){{ $when($campaign->starts_at) }} &ndash; {{ $when($campaign->ends_at) }}</p>
					<p class="tf-countdown" x-data="countdown" data-end="{{ $target->toIso8601String() }}" data-label="{{ $live ? $c['endsIn'] : $c['startsIn'] }}" role="timer">@include('store.partials.account-icon', ['name' => 'clock'])<span x-text="text">{{ $live ? $c['endsIn'] : $c['startsIn'] }}</span></p>
				</div>
			</div>
		</header>

		<section class="tf-promo-vouchers" x-data="vouchersPage" aria-label="{{ $c['vouchersTitle'] }}">
			<h2 class="tf-promo-h2" x-show="hasItems">{{ $c['vouchersTitle'] }}</h2>
			@include('store.partials.voucher-claim', ['c' => $c['claim'], 'showEmpty' => false])
		</section>

		<h2 class="tf-promo-h2">{{ $c['productsTitle'] }}</h2>
		@if ($items->isEmpty())
			<p class="tf-shop-note">{{ $c['noProducts'] }}</p>
		@else
			<div class="tf-cat-grid">
				@foreach ($items as $x)
					<article class="tf-cat-card">
						<a href="{{ $x['path'] }}" class="tf-cat-link" aria-hidden="true" tabindex="-1">
							<figure>
								@if ($x['photos'])
									<img src="{{ $x['photos'][0]['src'] }}" alt="{{ $x['name'][$locale] }}" width="640" height="640" loading="lazy" decoding="async">
								@endif
							</figure>
						</a>
						<div class="tf-cat-card-body">
							<h3><a href="{{ $x['path'] }}">{{ $x['name'][$locale] }}</a></h3>
							<div class="tf-cat-price"><strong>{{ $site->rupiah($x['price']) }}</strong>@if ($x['normal'] > $x['price'])<s>{{ $site->rupiah($x['normal']) }}</s><em class="tf-off">-{{ $site->offPercent($x) }}%</em>@endif</div>
							@if ($x['sold'] !== null && $x['sold'] > 0)<p class="tf-sold">{{ $site->fill($c['sold'], ['n' => $x['sold']]) }}</p>@endif
							@include('store.partials.cart-button', ['sku' => $x['sku'], 'name' => $x['name'][$locale]])
						</div>
					</article>
				@endforeach
			</div>
		@endif
	</main>
@endsection
