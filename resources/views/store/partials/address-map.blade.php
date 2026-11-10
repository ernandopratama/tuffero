{{-- Peta titik rumah (checkout dan Alamat Saya). $prefix: awalan id; $words: teks checkout. Logika di resources/js/store/addressPicker.js. --}}
<div class="tf-map">
	<p class="tf-map-title" id="{{ $prefix }}-map-title">{{ $words['map']['title'] }}</p>
	<p class="tf-field-hint">{{ $words['map']['hint'] }}</p>
	<template x-if="!mapFailed">
		<div class="tf-map-canvas" role="application" aria-labelledby="{{ $prefix }}-map-title" aria-roledescription="{{ $words['map']['label'] }}" x-init="mountMap($el)"></div>
	</template>
	<template x-if="mapFailed"><p class="tf-shop-issue" role="alert">{{ $words['map']['failed'] }}</p></template>
	<template x-if="hasPinText">
		<div class="tf-map-address" aria-live="polite">
			{{ $site->lineIcon('pin', 20) }}
			<div><small>{{ $words['map']['pinAddress'] }}</small><p x-text="pinText"></p></div>
		</div>
	</template>
	<template x-if="invalid('latitude')"><p class="tf-shop-issue" role="alert" x-text="errorFor('latitude')"></p></template>
	<small class="tf-map-credit">{{ $words['map']['credit'] }}</small>
</div>
