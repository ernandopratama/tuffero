@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-orders-data">{{ $site->json(['items' => $c['items'], 'status' => $pg['order']['status']]) }}</script>
	<main class="tf-wrap tf-shop" x-data="accountOrdersPage">
		<p class="tf-eyebrow"><a href="{{ $site->route('profile') }}">{{ $c['back'] }}</a></p>
		<h1 class="tf-shop-title">{{ $c['title'] }}</h1>

		<template x-if="loading"><p class="tf-shop-lead" role="status">{{ $c['loading'] }}</p></template>
		<template x-if="failed">
			<div class="tf-auth-notice" role="alert">
				{{ $c['failed'] }}
				<button type="button" class="tf-shop-link" @click="retry">{{ $c['retry'] }}</button>
			</div>
		</template>
		<template x-if="isEmpty">
			<div class="tf-shop-card tf-account-section">
				<h2>{{ $c['emptyTitle'] }}</h2>
				<p class="tf-shop-note">{{ $c['emptyText'] }}</p>
				<a href="{{ $site->route('products') }}" class="tf-btn tf-account-cta">{{ $c['shop'] }}</a>
			</div>
		</template>
		<template x-if="hasItems">
			<div style="display: contents">
				<ul class="tf-account-orders tf-shop-card tf-account-section">
					<template x-for="order in items" :key="order.public_ref">
						<li>
							<a :href="href(order)">
								<span><strong x-text="order.order_number"></strong><em x-text="meta2(order)"></em></span>
								<span><strong x-text="rupiah(order.total)"></strong><em x-text="label(order)"></em></span>
							</a>
						</li>
					</template>
				</ul>
				<template x-if="showPages">
					<nav class="tf-account-pages" aria-label="{{ $c['pages'] }}">
						<button type="button" class="tf-shop-link" :disabled="meta.current_page <= 1" @click="prev">{{ $c['prev'] }}</button>
						<span x-text="pageText"></span>
						<button type="button" class="tf-shop-link" :disabled="meta.current_page >= meta.last_page" @click="next">{{ $c['next'] }}</button>
					</nav>
				</template>
			</div>
		</template>
	</main>
@endsection
