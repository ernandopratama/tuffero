@extends('store.layouts.app')

@php
	$co = $pg['checkout'];
	$fields = [
		['key' => 'label', 'label' => $c['label'], 'attrs' => ['maxlength' => 50, 'placeholder' => $c['labelPlaceholder']]],
		['key' => 'recipient_name', 'label' => $co['name'], 'attrs' => ['maxlength' => 120, 'autocomplete' => 'name']],
		['key' => 'phone', 'label' => $co['phone'], 'prefix' => '+62', 'attrs' => ['type' => 'tel', 'maxlength' => 20, 'inputmode' => 'tel', 'autocomplete' => 'tel-national', 'placeholder' => '812-3456-7890']],
		['key' => 'street', 'label' => $co['street'], 'wide' => true, 'attrs' => ['maxlength' => 255, 'autocomplete' => 'street-address']],
		['key' => 'district', 'label' => $co['district'], 'attrs' => ['maxlength' => 100]],
		['key' => 'city', 'label' => $co['city'], 'attrs' => ['maxlength' => 100, 'autocomplete' => 'address-level2']],
		['key' => 'province', 'label' => $co['province'], 'attrs' => ['maxlength' => 100, 'autocomplete' => 'address-level1']],
		['key' => 'postal_code', 'label' => $co['postal'], 'attrs' => ['maxlength' => 5, 'inputmode' => 'numeric', 'autocomplete' => 'postal-code']],
	];
@endphp

@section('content')
	<script type="application/json" id="tf-addresses-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['errors', 'failed', 'tooMany', 'saved', 'deleted', 'confirmDelete', 'editTitle', 'addTitle']), 'checkoutErrors' => $co['errors']]) }}</script>
	<main class="tf-wrap tf-shop" x-data="addressesPage">
		<p class="tf-eyebrow"><a href="{{ $site->route('profile') }}">{{ $c['back'] }}</a></p>
		<h1 class="tf-shop-title">{{ $c['title'] }}</h1>

		<template x-if="initialLoading"><p class="tf-shop-lead" role="status">{{ $c['loading'] }}</p></template>
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
										<template x-if="invalid('{{ $field['key'] }}')"><p role="alert" x-text="errorFor('{{ $field['key'] }}')"></p></template>
									</div>
								@endforeach
							</div>
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
					<div class="tf-shop-card tf-account-section">
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
									<h2><span x-text="address.label"></span> <template x-if="address.is_default"><span class="tf-account-badge">{{ $c['default'] }}</span></template></h2>
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
