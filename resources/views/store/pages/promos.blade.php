@extends('store.layouts.app')

@php
	$when = fn ($date) => $date->copy()->locale(app()->getLocale())->translatedFormat('j M Y, H:i');
@endphp

@section('content')
	<main class="tf-wrap tf-shop tf-campaign">
		<header class="tf-acct-hero is-compact tf-promo-hero">
			<span class="tf-acct-hero-ico">@include('store.partials.account-icon', ['name' => 'voucher'])</span>
			<div class="tf-acct-hero-text">
				<p class="tf-promo-eyebrow">{{ $c['eyebrow'] }}</p>
				<h1 class="tf-acct-title">{{ $c['title'] }}</h1>
				<p class="tf-acct-lead">{{ $c['lead'] }}</p>
			</div>
		</header>

		@if ($campaigns->isEmpty())
			<div class="tf-shop-card tf-account-section tf-acct-emptybox">
				<span class="tf-acct-ico is-lg">@include('store.partials.account-icon', ['name' => 'voucher'])</span>
				<h2>{{ $c['emptyTitle'] }}</h2>
				<p class="tf-shop-note">{{ $c['emptyText'] }}</p>
				<a href="{{ $site->route('products') }}" class="tf-btn tf-account-cta">{{ $c['shop'] }}</a>
			</div>
		@else
			<ul class="tf-campaign-list">
				@foreach ($campaigns as $campaign)
					@php $live = $campaign->starts_at->lte(now()); @endphp
					<li>
						<a href="{{ $site->route('promos') }}/{{ $campaign->slug }}">
							<span class="tf-campaign-media">
								@if ($campaign->image_path)
									<img src="/storage/{{ $campaign->image_path }}" alt="" width="600" height="200" loading="lazy" decoding="async">
								@else
									<span class="tf-campaign-blank">@include('store.partials.account-icon', ['name' => 'voucher'])</span>
								@endif
								<span class="tf-campaign-badges">
									@if ($campaign->is_flash)<em class="tf-campaign-flash">@include('store.partials.account-icon', ['name' => 'flash']){{ $c['flash'] }}</em>@endif
									<em @class(['tf-campaign-state', 'is-live' => $live])>{{ $live ? $c['live'] : $c['upcoming'] }}</em>
								</span>
							</span>
							<span class="tf-promo-card-body">
								<strong>{{ $campaign->title }}</strong>
								<small>@include('store.partials.account-icon', ['name' => 'calendar']){{ $when($campaign->starts_at) }} &ndash; {{ $when($campaign->ends_at) }}</small>
								<span class="tf-acct-more">{{ $c['view'] }}@include('store.partials.account-icon', ['name' => 'arrow'])</span>
							</span>
						</a>
					</li>
				@endforeach
			</ul>
		@endif
	</main>
@endsection
