@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-account-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['hello', 'title', 'errors', 'failed', 'tooMany', 'saved', 'verified', 'unverified', 'noPhone']), 'passwordSaved' => $c['password']['saved'], 'status' => $pg['order']['status']]) }}</script>
	<main class="tf-wrap tf-shop" x-data="accountPage">
		<p class="tf-eyebrow">{{ $c['eyebrow'] }}</p>
		<h1 class="tf-shop-title" x-text="heading">{{ $c['title'] }}</h1>

		<template x-if="loadingProfile"><p class="tf-shop-lead" role="status">{{ $c['loading'] }}</p></template>
		<template x-if="loadFailed">
			<div class="tf-auth-notice" role="alert">
				{{ $c['loadFailed'] }}
				<button type="button" class="tf-shop-link" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>

		<template x-if="ready">
			<div class="tf-account-grid">
				<section class="tf-shop-card tf-account-last">
					<h2>{{ $c['lastOrder'] }}</h2>
					<template x-if="lastOrder">
						<a :href="lastHref" class="tf-account-order">
							<span><strong x-text="lastOrder.order_number"></strong><em x-text="orderDate(lastOrder)"></em></span>
							<span><strong x-text="rupiah(lastOrder.total)"></strong><em x-text="lastLabel"></em></span>
						</a>
					</template>
					<template x-if="showNoOrders"><p class="tf-shop-note">{{ $c['noOrders'] }}</p></template>
					<a href="{{ $site->route('orders') }}" class="tf-shop-link">{{ $c['allOrders'] }}</a>
				</section>
				<section class="tf-shop-card tf-account-last">
					<h2>{{ $c['mainAddress'] }}</h2>
					<template x-if="mainAddress"><p class="tf-shop-note" x-text="addressText"></p></template>
					<template x-if="showNoAddress"><p class="tf-shop-note">{{ $c['noAddress'] }}</p></template>
					<a href="{{ $site->route('addresses') }}" class="tf-shop-link">{{ $c['manageAddresses'] }}</a>
				</section>
				<section class="tf-shop-card">
					<h2>{{ $c['profile'] }}</h2>
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
					<dl class="tf-account-facts">
						<div>
							<dt>{{ $c['email'] }}</dt>
							<dd><span class="tf-account-value" x-text="profile.email"></span> <span :class="profile.email_verified ? 'is-ok' : ''" x-text="emailState"></span></dd>
						</div>
						<div>
							<dt>{{ $c['phone'] }}</dt>
							<dd><span class="tf-account-value" x-text="phoneText"></span> <span :class="profile.phone_verified ? 'is-ok' : ''" x-text="phoneState"></span></dd>
						</div>
					</dl>
					<p class="tf-shop-note">{{ $c['contactNote'] }}</p>
				</section>

				<section class="tf-shop-card">
					<h2>{{ $c['password']['title'] }}</h2>
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
		</template>
	</main>
@endsection
