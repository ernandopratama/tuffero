@extends('store.layouts.app')

@php
	$co = $pg['checkout'];
	$fields = [
		['key' => 'label', 'label' => $c['label'], 'attrs' => ['maxlength' => 50, 'placeholder' => $c['labelPlaceholder']]],
		['key' => 'recipient_name', 'label' => $co['name'], 'attrs' => ['maxlength' => 120, 'autocomplete' => 'name']],
		['key' => 'phone', 'label' => $co['phone'], 'prefix' => '+62', 'attrs' => ['type' => 'tel', 'maxlength' => 20, 'inputmode' => 'tel', 'autocomplete' => 'tel-national', 'placeholder' => '812-3456-7890']],
		['key' => 'street', 'label' => $co['street'], 'wide' => true, 'hint' => $co['streetHint'], 'attrs' => ['maxlength' => 255, 'autocomplete' => 'street-address', 'aria-describedby' => 'ad-street-hint']],
	];
@endphp

@section('content')
	<script type="application/json" id="tf-addresses-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['errors', 'failed', 'tooMany', 'saved', 'deleted', 'confirmDelete', 'editTitle', 'addTitle']), 'checkoutErrors' => $co['errors'], 'search' => $co['search'], 'map' => $co['map']]) }}</script>
	<main class="tf-wrap tf-shop tf-acct" x-data="addressesPage">
		@include('store.partials.account-head', ['title' => $c['title'], 'back' => $c['back'], 'icon' => 'address'])

		<template x-if="initialLoading"><div class="tf-acct-skel is-list" role="status"><span class="visually-hidden">{{ $c['loading'] }}</span><div></div><div></div><div></div></div></template>
		<template x-if="loadFailed">
			<div class="tf-auth-notice" role="alert">
				{{ $c['loadFailed'] }}
				<button type="button" class="tf-shop-link" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>

		<template x-if="ready">
			<div style="display: contents">
				<template x-if="status"><p class="tf-auth-notice" role="status" x-text="status"></p></template>
				<template x-if="listFailed"><p class="tf-auth-notice" role="alert" x-text="failed"></p></template>

				<template x-if="isEditing">
					<section class="tf-shop-card tf-account-section">
						<h2 x-text="formTitle"></h2>
						<form novalidate @submit.prevent="save">
							<div class="tf-shop-fields">
								@foreach ($fields as $field)
									<div @class(['tf-auth-field', 'is-wide' => $field['wide'] ?? false]) :class="fieldClass('{{ $field['key'] }}')">
										<label for="ad-{{ $field['key'] }}">{{ $field['label'] }}</label>
										<div class="tf-auth-input">@if ($field['prefix'] ?? false)<span aria-hidden="true">{{ $field['prefix'] }}</span>@endif<input id="ad-{{ $field['key'] }}" x-model="form.{{ $field['key'] }}" @foreach ($field['attrs'] as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach :aria-invalid="invalid('{{ $field['key'] }}')"></div>
										@if ($field['hint'] ?? false)<p id="ad-{{ $field['key'] }}-hint" class="tf-field-hint">{{ $field['hint'] }}</p>@endif
										<template x-if="invalid('{{ $field['key'] }}')"><p role="alert" x-text="errorFor('{{ $field['key'] }}')"></p></template>
									</div>
								@endforeach
								{{-- Urutan dan dropdown sama dengan checkout (D-074, D-077). --}}
								@include('store.partials.region-combo', ['field' => 'province', 'label' => $co['province'], 'prefix' => 'ad', 'words' => $co])
								@include('store.partials.region-combo', ['field' => 'district', 'label' => $co['district'], 'prefix' => 'ad', 'words' => $co])
								@include('store.partials.region-combo', ['field' => 'city', 'label' => $co['city'], 'prefix' => 'ad', 'words' => $co])
								<div class="tf-auth-field" :class="fieldClass('postal_code')">
									<label for="ad-postal_code">{{ $co['postal'] }}</label>
									<div class="tf-auth-input"><input id="ad-postal_code" x-model="form.postal_code" list="ad-postal-list" autocomplete="postal-code" inputmode="numeric" maxlength="5" :aria-invalid="invalid('postal_code')"></div>
									<datalist id="ad-postal-list"><template x-for="code in postalOptions" :key="code"><option :value="code"></option></template></datalist>
									<template x-if="invalid('postal_code')"><p role="alert" x-text="errorFor('postal_code')"></p></template>
								</div>
							</div>
							@include('store.partials.address-map', ['prefix' => 'ad', 'words' => $co])
							<label class="tf-account-check"><input x-model="form.is_default" type="checkbox"> {{ $c['makeDefault'] }}</label>
							<template x-if="formFailed"><p class="tf-auth-notice" role="alert" x-text="failed"></p></template>
							<div class="tf-account-actions">
								<button type="submit" class="tf-btn" :disabled="busy">{{ $c['save'] }}</button>
								<button type="button" class="tf-shop-link" @click="closeForm">{{ $c['cancel'] }}</button>
							</div>
						</form>
					</section>
				</template>

				<template x-if="showEmpty">
					<div class="tf-shop-card tf-account-section tf-acct-emptybox">
						<span class="tf-acct-ico is-lg">@include('store.partials.account-icon', ['name' => 'address'])</span>
						<h2>{{ $c['emptyTitle'] }}</h2>
						<p class="tf-shop-note">{{ $c['emptyText'] }}</p>
						<button type="button" class="tf-btn tf-account-cta" @click="openNew">{{ $c['add'] }}</button>
					</div>
				</template>

				<template x-if="showList">
					<div style="display: contents">
						<ul class="tf-account-addresses">
							<template x-for="address in items" :key="address.id">
								<li class="tf-shop-card">
									<h2 class="tf-acct-tile-head"><span class="tf-acct-ico" aria-hidden="true">@include('store.partials.account-icon', ['name' => 'address'])</span><span x-text="address.label"></span> <template x-if="address.is_default"><span class="tf-account-badge">{{ $c['default'] }}</span></template></h2>
									<template x-for="line in lines(address)" :key="line"><p x-text="line"></p></template>
									<div class="tf-account-actions">
										<button type="button" class="tf-shop-link" :disabled="busy" @click="openForm(address)">{{ $c['edit'] }}</button>
										<template x-if="!address.is_default"><button type="button" class="tf-shop-link" :disabled="busy" @click="makeDefault(address)">{{ $c['setDefault'] }}</button></template>
										<button type="button" class="tf-shop-link" :disabled="busy" @click="remove(address)">{{ $c['delete'] }}</button>
									</div>
								</li>
							</template>
						</ul>
						<template x-if="canAdd"><button type="button" class="tf-btn tf-account-cta" @click="openNew">{{ $c['add'] }}</button></template>
						<template x-if="atLimit"><p class="tf-shop-note">{{ $c['errors']['limit_reached'] }}</p></template>
					</div>
				</template>
			</div>
		</template>
	</main>
@endsection
