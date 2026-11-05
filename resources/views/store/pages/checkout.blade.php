@extends('store.layouts.app')

@php
	$cartWords = $pg['cart'];
@endphp

@section('content')
	<script type="application/json" id="tf-checkout-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['errors', 'tooMany', 'failed', 'lead', 'leadSignedIn', 'submit', 'submitting', 'shippingPending', 'saveLabelDefault']), 'customer' => $storeUser && ! $storeRole]) }}</script>
	<main class="tf-wrap tf-shop tf-checkout" x-data="checkoutPage">
		<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
		<p class="tf-shop-lead" x-text="leadText">{{ $c['lead'] }}</p>

		<template x-if="loading"><p class="tf-shop-note" role="status">{{ $cartWords['loading'] }}</p></template>
		<template x-if="loadFailed">
			<div class="tf-cat-empty" role="alert">
				<p>{{ $c['failed'] }}</p>
				<button type="button" class="tf-btn" @click="loadCart">{{ $cartWords['retry'] }}</button>
			</div>
		</template>
		<template x-if="empty">
			<div class="tf-cat-empty">
				<h2>{{ $cartWords['empty'] }}</h2>
				<a href="{{ $site->route('products') }}" class="tf-btn">{{ $cartWords['browse'] }}</a>
			</div>
		</template>
		<template x-if="blocked">
			<div class="tf-cat-empty" role="alert">
				<h2>{{ $c['cartProblem'] }}</h2>
				<a href="{{ $site->route('cart') }}" class="tf-btn">{{ $c['backToCart'] }}</a>
			</div>
		</template>

		<template x-if="canFill">
			<form class="tf-shop-grid" novalidate @submit.prevent="submit">
				<div class="tf-shop-stack">
					<section class="tf-shop-card">
						<h2>{{ $c['contactTitle'] }}</h2>
						<div class="tf-shop-fields">
							<div class="tf-auth-field is-wide" :class="fieldClass('customer_name')">
								<label for="co-name">{{ $c['name'] }}</label>
								<div class="tf-auth-input"><input id="co-name" x-model="form.customer_name" autocomplete="name" maxlength="120" :aria-invalid="invalid('customer_name')" @input="clearError('customer_name')"></div>
								<template x-if="invalid('customer_name')"><p role="alert" x-text="errorFor('customer_name')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('customer_email')">
								<label for="co-email">{{ $c['email'] }}</label>
								<div class="tf-auth-input"><input id="co-email" x-model="form.customer_email" type="email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="190" :aria-invalid="invalid('customer_email')" @input="clearError('customer_email')"></div>
								<template x-if="invalid('customer_email')"><p role="alert" x-text="errorFor('customer_email')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('customer_phone')">
								<label for="co-phone">{{ $c['phone'] }}</label>
								<div class="tf-auth-input"><span aria-hidden="true">+62</span><input id="co-phone" x-model="form.customer_phone" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="20" placeholder="812-3456-7890" :aria-invalid="invalid('customer_phone')" @input="clearError('customer_phone')"></div>
								<template x-if="invalid('customer_phone')"><p role="alert" x-text="errorFor('customer_phone')"></p></template>
							</div>
						</div>
					</section>

					<section class="tf-shop-card">
						<h2>{{ $c['addressTitle'] }}</h2>
						<template x-if="addresses.length">
							<div class="tf-auth-field">
								<label for="co-saved">{{ $c['savedAddress'] }}</label>
								<div class="tf-auth-input"><select id="co-saved" :value="addressId" @change="chooseAddress">
									<template x-for="address in addresses" :key="address.id"><option :value="address.id" :selected="address.id === addressId" x-text="addressLabel(address)"></option></template>
									<option value="" :selected="addressId === ''">{{ $c['newAddress'] }}</option>
								</select></div>
							</div>
						</template>
						<div class="tf-shop-fields">
							<div class="tf-auth-field is-wide" :class="fieldClass('street')">
								<label for="co-street">{{ $c['street'] }}</label>
								<div class="tf-auth-input"><input id="co-street" x-model="form.street" autocomplete="street-address" maxlength="255" :aria-invalid="invalid('street')" @input="clearError('street')"></div>
								<template x-if="invalid('street')"><p role="alert" x-text="errorFor('street')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('district')">
								<label for="co-district">{{ $c['district'] }}</label>
								<div class="tf-auth-input"><input id="co-district" x-model="form.district" maxlength="100" :aria-invalid="invalid('district')" @input="clearError('district')"></div>
								<template x-if="invalid('district')"><p role="alert" x-text="errorFor('district')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('city')">
								<label for="co-city">{{ $c['city'] }}</label>
								<div class="tf-auth-input"><input id="co-city" x-model="form.city" autocomplete="address-level2" maxlength="100" :aria-invalid="invalid('city')" @input="clearError('city')"></div>
								<template x-if="invalid('city')"><p role="alert" x-text="errorFor('city')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('province')">
								<label for="co-province">{{ $c['province'] }}</label>
								<div class="tf-auth-input"><input id="co-province" x-model="form.province" autocomplete="address-level1" maxlength="100" :aria-invalid="invalid('province')" @input="clearError('province')"></div>
								<template x-if="invalid('province')"><p role="alert" x-text="errorFor('province')"></p></template>
							</div>
							<div class="tf-auth-field" :class="fieldClass('postal_code')">
								<label for="co-postal">{{ $c['postal'] }}</label>
								<div class="tf-auth-input"><input id="co-postal" x-model="form.postal_code" autocomplete="postal-code" inputmode="numeric" maxlength="5" :aria-invalid="invalid('postal_code')" @input="clearError('postal_code')"></div>
								<template x-if="invalid('postal_code')"><p role="alert" x-text="errorFor('postal_code')"></p></template>
							</div>
						</div>
						<template x-if="canSaveAddress">
							<div style="display: contents">
								<label class="tf-account-check"><input x-model="saveAddress" type="checkbox"> {{ $c['saveAddress'] }}</label>
								<template x-if="saveAddress">
									<div class="tf-auth-field">
										<label for="co-save-label">{{ $c['saveLabel'] }}</label>
										<div class="tf-auth-input"><input id="co-save-label" x-model="saveLabel" maxlength="50"></div>
									</div>
								</template>
							</div>
						</template>
					</section>

					<section class="tf-shop-card">
						<h2 id="co-courier">{{ $c['courierTitle'] }}</h2>
						<template x-if="ratesLoading"><p class="tf-shop-note" role="status">{{ $c['courierLoading'] }}</p></template>
						<template x-if="!ratesLoading && ratesFailed"><p class="tf-shop-issue" role="alert">{{ $c['courierFailed'] }}</p></template>
						<template x-if="courierHint"><p class="tf-shop-note">{{ $c['courierHint'] }}</p></template>
						<template x-if="!ratesLoading && !ratesFailed && hasRates">
							<div class="tf-shop-rates" role="radiogroup" aria-labelledby="co-courier">
								<template x-for="rate in rates" :key="rateKey(rate)">
									<label>
										<input type="radio" name="courier" :checked="isSelected(rate)" @change="pickRate(rate)">
										<span x-text="rate.label"></span>
										<strong x-text="rupiah(rate.fee)"></strong>
									</label>
								</template>
							</div>
						</template>
					</section>
				</div>

				<aside class="tf-shop-card tf-shop-summary">
					<h2>{{ $c['summary'] }}</h2>
					<ul class="tf-shop-lines">
						<template x-for="item in cart.items" :key="item.sku"><li><span x-text="lineLabel(item)"></span><span x-text="rupiah(item.line_total)"></span></li></template>
					</ul>
					<dl>
						<div><dt>{{ $c['subtotal'] }}</dt><dd x-text="subtotalText"></dd></div>
						<div><dt>{{ $c['shipping'] }}</dt><dd x-text="feeText"></dd></div>
						<div class="is-total"><dt>{{ $c['total'] }}</dt><dd x-text="totalText"></dd></div>
					</dl>
					<p class="tf-shop-note">{{ $c['serverNote'] }}</p>
					<template x-if="formError"><p class="tf-shop-issue" role="alert" x-text="formError"></p></template>
					<button type="submit" class="tf-btn tf-btn-block" :disabled="submitting" x-text="submitText"></button>
					<a href="{{ $site->route('cart') }}" class="tf-auth-link">{{ $c['backToCart'] }}</a>
				</aside>
			</form>
		</template>
	</main>
@endsection
