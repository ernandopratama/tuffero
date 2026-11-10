@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-lookup-data">{{ $site->json(\Illuminate\Support\Arr::only($c, ['tooMany', 'failed'])) }}</script>
	<main class="tf-wrap tf-shop tf-lookup" x-data="lookupPage">
		<div class="tf-shop-card">
			<p class="tf-eyebrow">{{ $c['eyebrow'] }}</p>
			<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
			<p class="tf-shop-lead">{{ $c['lead'] }}</p>

			<template x-if="notFound">
				<div class="tf-auth-notice" role="alert"><strong>{{ $c['notFoundTitle'] }}</strong><br>{{ $c['notFoundText'] }}</div>
			</template>
			<template x-if="error"><p class="tf-auth-notice" role="alert" x-text="error"></p></template>

			<form @submit.prevent="submit">
				<div class="tf-auth-field">
					<label for="lk-number">{{ $c['number'] }}</label>
					<div class="tf-auth-input"><input id="lk-number" x-model="form.order_number" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="{{ $c['numberPlaceholder'] }}"></div>
				</div>
				<div class="tf-auth-field">
					<label for="lk-contact">{{ $c['contact'] }}</label>
					<div class="tf-auth-input"><input id="lk-contact" x-model="form.contact" required maxlength="190" autocomplete="off" spellcheck="false" placeholder="{{ $c['contactPlaceholder'] }}"></div>
				</div>
				<button type="submit" class="tf-btn tf-btn-block" :disabled="busy">{{ $c['submit'] }}</button>
			</form>
			<a href="{{ $site->route('contact') }}" class="tf-auth-link">{{ $c['help'] }}</a>
		</div>
	</main>
@endsection
