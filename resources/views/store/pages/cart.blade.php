@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-cart-data">{{ $site->json(\Illuminate\Support\Arr::only($c, ['insufficient', 'removeLabel', 'updateFailed'])) }}</script>
	<main class="tf-wrap tf-shop" x-data="cartPage">
		<h1 class="tf-shop-title">{{ $c['title'] }}</h1>

		<template x-if="loading"><p class="tf-shop-note" role="status">{{ $c['loading'] }}</p></template>
		<template x-if="failed">
			<div class="tf-cat-empty" role="alert">
				<p>{{ $c['loadFailed'] }}</p>
				<button type="button" class="tf-btn" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>
		<template x-if="empty">
			<div class="tf-cat-empty">
				<h2>{{ $c['empty'] }}</h2>
				<a href="{{ $site->route('products') }}" class="tf-btn">{{ $c['browse'] }}</a>
			</div>
		</template>

		<template x-if="filled">
			<div class="tf-shop-grid">
				<ul class="tf-shop-card tf-cart-list">
					<template x-for="item in cart.items" :key="item.sku">
						<li :class="item.issue ? 'has-issue' : ''">
							<template x-if="item.photo"><img :src="photoUrl(item)" alt="" width="88" height="88" loading="lazy"></template>
							<div class="tf-cart-info">
								<h2 x-text="productName(item)"></h2>
								<p class="tf-cart-price" x-text="rupiah(item.price)"></p>
								<template x-if="item.issue === 'unavailable'"><p class="tf-shop-issue" role="alert">{{ $c['unavailable'] }}</p></template>
								<template x-if="item.issue === 'insufficient_stock'"><p class="tf-shop-issue" role="alert" x-text="insufficient(item)"></p></template>
								<div class="tf-cart-actions">
									<div class="tf-pd-stepper" role="group" aria-label="{{ $c['qty'] }}">
										<button type="button" aria-label="{{ $c['qtyDec'] }}" :disabled="busy || item.quantity <= 1" @click="dec(item)">-</button>
										<span aria-live="polite" x-text="item.quantity"></span>
										<button type="button" aria-label="{{ $c['qtyInc'] }}" :disabled="busy || item.quantity >= (item.max_quantity || 50)" @click="inc(item)">+</button>
									</div>
									<button type="button" class="tf-shop-link" :disabled="busy" :aria-label="removeLabel(item)" @click="remove(item)">{{ $c['remove'] }}</button>
								</div>
							</div>
							<strong class="tf-cart-line" x-text="rupiah(item.line_total)"></strong>
						</li>
					</template>
				</ul>

				<aside class="tf-shop-card tf-shop-summary">
					<h2>{{ $c['summary'] }}</h2>
					<dl>
						<div><dt>{{ $c['subtotal'] }}</dt><dd x-text="rupiah(cart.subtotal)"></dd></div>
					</dl>
					<p class="tf-shop-note">{{ $c['shippingLater'] }}</p>
					<template x-if="updateError"><p class="tf-shop-issue" role="alert" x-text="updateError"></p></template>
					<template x-if="cart.can_checkout"><a href="{{ $site->route('checkout') }}" class="tf-btn tf-btn-block">{{ $c['checkout'] }}</a></template>
					<a href="{{ $site->route('products') }}" class="tf-auth-link">{{ $c['continue'] }}</a>
				</aside>
			</div>
		</template>
	</main>
@endsection
