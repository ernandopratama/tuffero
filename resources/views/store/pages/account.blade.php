@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-account-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['hello', 'title', 'errors', 'failed', 'tooMany', 'saved', 'verified', 'unverified', 'noPhone']), 'passwordSaved' => $c['password']['saved'], 'status' => $pg['order']['status']]) }}</script>
	<main class="tf-wrap tf-shop tf-acct" x-data="accountPage">
		{{-- Kepala halaman: inisial nama, sapaan, dan ringkasan isi halaman. --}}
		<header class="tf-acct-hero">
			<span class="tf-acct-avatar" aria-hidden="true" x-text="initials"></span>
			<div class="tf-acct-hero-text">
				<p class="tf-eyebrow tf-eyebrow-gold">{{ $c['eyebrow'] }}</p>
				<h1 class="tf-acct-title" x-text="heading">{{ $c['title'] }}</h1>
				<p class="tf-acct-lead">{{ $c['lead'] }}</p>
			</div>
		</header>

		<template x-if="loadingProfile">
			<div class="tf-acct-skel" role="status">
				<span class="visually-hidden">{{ $c['loading'] }}</span>
				<div></div><div></div><div></div>
			</div>
		</template>
		<template x-if="loadFailed">
			<div class="tf-auth-notice" role="alert">
				{{ $c['loadFailed'] }}
				<button type="button" class="tf-shop-link" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>

		<template x-if="ready">
			<div>
				<div class="tf-acct-tiles">
					<section class="tf-acct-tile" aria-labelledby="ac-t-order">
						<div class="tf-acct-tile-head">
							<span class="tf-acct-ico">@include('store.partials.account-icon', ['name' => 'order'])</span>
							<h2 id="ac-t-order">{{ $c['lastOrder'] }}</h2>
						</div>
						<template x-if="lastOrder">
							<a :href="lastHref" class="tf-acct-order">
								<span class="tf-acct-order-top"><strong x-text="lastOrder.order_number"></strong><span class="tf-acct-pill" x-text="lastLabel"></span></span>
								<span class="tf-acct-order-meta"><span x-text="orderDate(lastOrder)"></span><strong x-text="rupiah(lastOrder.total)"></strong></span>
							</a>
						</template>
						<template x-if="showNoOrders"><p class="tf-acct-empty">{{ $c['noOrders'] }}</p></template>
						<a href="{{ $site->route('orders') }}" class="tf-acct-more">{{ $c['allOrders'] }}@include('store.partials.account-icon', ['name' => 'arrow'])</a>
					</section>
					<section class="tf-acct-tile" aria-labelledby="ac-t-address">
						<div class="tf-acct-tile-head">
							<span class="tf-acct-ico">@include('store.partials.account-icon', ['name' => 'address'])</span>
							<h2 id="ac-t-address">{{ $c['mainAddress'] }}</h2>
						</div>
						<template x-if="mainAddress"><p class="tf-acct-text" x-text="addressText"></p></template>
						<template x-if="showNoAddress"><p class="tf-acct-empty">{{ $c['noAddress'] }}</p></template>
						<a href="{{ $site->route('addresses') }}" class="tf-acct-more">{{ $c['manageAddresses'] }}@include('store.partials.account-icon', ['name' => 'arrow'])</a>
					</section>
					<section class="tf-acct-tile" aria-labelledby="ac-t-voucher">
						<div class="tf-acct-tile-head">
							<span class="tf-acct-ico">@include('store.partials.account-icon', ['name' => 'voucher'])</span>
							<h2 id="ac-t-voucher">{{ $c['vouchersCard'] }}</h2>
						</div>
						<p class="tf-acct-text">{{ $c['vouchersNote'] }}</p>
						<a href="{{ $site->route('myVouchers') }}" class="tf-acct-more">{{ $c['myVouchers'] }}@include('store.partials.account-icon', ['name' => 'arrow'])</a>
					</section>
				</div>

				<h2 class="tf-acct-section-title">{{ $c['settings'] }}</h2>
				<div class="tf-acct-settings">
					<section class="tf-shop-card tf-acct-card" aria-labelledby="ac-s-profile">
						<div class="tf-acct-tile-head">
							<span class="tf-acct-ico">@include('store.partials.account-icon', ['name' => 'profile'])</span>
							<h2 id="ac-s-profile">{{ $c['profile'] }}</h2>
						</div>
						<form novalidate @submit.prevent="saveName">
							<div class="tf-auth-field" :class="nameError ? 'is-error' : ''">
								<label for="ac-name">{{ $c['name'] }}</label>
								<div class="tf-auth-input"><input id="ac-name" x-model="name" maxlength="120" autocomplete="name" :aria-invalid="!!nameError" :aria-describedby="nameError ? 'ac-name-error' : null"></div>
								<template x-if="nameError"><p id="ac-name-error" role="alert" x-text="nameError"></p></template>
							</div>
							<template x-if="nameFailed"><p class="tf-auth-notice" role="alert" x-text="nameFailed"></p></template>
							<template x-if="nameStatus"><p class="tf-auth-notice" role="status" x-text="nameStatus"></p></template>
							<button type="submit" class="tf-btn tf-btn-block" :disabled="savingName">{{ $c['save'] }}</button>
						</form>
						<dl class="tf-acct-facts">
							<div>
								<dt>{{ $c['email'] }}</dt>
								<dd><span class="tf-acct-value" x-text="profile.email"></span> <span class="tf-acct-state" :class="profile.email_verified ? 'is-ok' : ''" x-text="emailState"></span></dd>
							</div>
							<div>
								<dt>{{ $c['phone'] }}</dt>
								<dd><span class="tf-acct-value" x-text="phoneText"></span> <span class="tf-acct-state" :class="profile.phone_verified ? 'is-ok' : ''" x-text="phoneState"></span></dd>
							</div>
						</dl>
						<p class="tf-shop-note">{{ $c['contactNote'] }}</p>
					</section>

					<section class="tf-shop-card tf-acct-card" aria-labelledby="ac-s-password">
						<div class="tf-acct-tile-head">
							<span class="tf-acct-ico">@include('store.partials.account-icon', ['name' => 'password'])</span>
							<h2 id="ac-s-password">{{ $c['password']['title'] }}</h2>
						</div>
						<p class="tf-shop-note">{{ $c['password']['note'] }}</p>
						<form novalidate @submit.prevent="savePassword">
							<div class="tf-auth-field" :class="pwClass('current_password')">
								<label for="ac-current">{{ $c['password']['current'] }}</label>
								<div class="tf-auth-input"><input id="ac-current" x-model="pw.current_password" type="password" autocomplete="current-password"></div>
								<template x-if="pwError('current_password')"><p role="alert" x-text="pwError('current_password')"></p></template>
							</div>
							<div class="tf-auth-field" :class="pwClass('password')">
								<label for="ac-new">{{ $c['password']['new'] }}</label>
								<div class="tf-auth-input"><input id="ac-new" x-model="pw.password" type="password" autocomplete="new-password"></div>
								<template x-if="pwError('password')"><p role="alert" x-text="pwError('password')"></p></template>
								<template x-if="!pwError('password')"><p>{{ $c['password']['hint'] }}</p></template>
							</div>
							<div class="tf-auth-field">
								<label for="ac-confirm">{{ $c['password']['confirm'] }}</label>
								<div class="tf-auth-input"><input id="ac-confirm" x-model="pw.password_confirmation" type="password" autocomplete="new-password"></div>
							</div>
							<template x-if="pwFailed"><p class="tf-auth-notice" role="alert" x-text="pwFailed"></p></template>
							<template x-if="pwStatus"><p class="tf-auth-notice" role="status" x-text="pwStatus"></p></template>
							<button type="submit" class="tf-btn tf-btn-block" :disabled="savingPw">{{ $c['password']['submit'] }}</button>
						</form>
					</section>
				</div>
			</div>
		</template>
	</main>
@endsection
