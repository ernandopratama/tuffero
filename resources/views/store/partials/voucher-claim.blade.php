{{-- Daftar voucher yang perlu diklaim (komponen vouchersPage). Dipakai halaman Voucher dan halaman kampanye. $c = teks voucher, $showEmpty = tampilkan keadaan kosong. --}}
<template x-if="loading"><p class="tf-shop-note" role="status">{{ $c['loading'] }}</p></template>
<template x-if="failed">
	<div class="tf-auth-notice" role="alert">
		{{ $c['failed'] }}
		<button type="button" class="tf-shop-link" @click="load">{{ $c['retry'] }}</button>
	</div>
</template>
@if ($showEmpty ?? true)
	<template x-if="isEmpty">
		<div class="tf-shop-card tf-account-section">
			<h2>{{ $c['emptyTitle'] }}</h2>
			<p class="tf-shop-note">{{ $c['emptyText'] }}</p>
			<a href="{{ $site->route('products') }}" class="tf-btn tf-account-cta">{{ $c['shop'] }}</a>
		</div>
	</template>
@endif
<template x-if="showSignIn"><p class="tf-shop-note">{{ $c['signInHint'] }}</p></template>
<template x-if="notice"><p class="tf-shop-note" role="status"><span x-text="notice"></span> <a href="{{ $site->route('myVouchers') }}" class="tf-shop-link">{{ $c['mine'] }}</a></p></template>
<template x-if="error"><p class="tf-shop-issue" role="alert" x-text="error"></p></template>
<template x-if="hasItems">
	<ul class="tf-voucher-list tf-voucher-page">
		<template x-for="v in items" :key="v.code">
			<li :class="cardClass(v)">
				<div>
					<strong x-text="title(v)"></strong>
					<span x-text="description(v)"></span>
					<small x-text="meta(v)"></small>
				</div>
				<button type="button" class="tf-btn" :disabled="!canClaim(v)" @click="claim(v)" x-text="actionText(v)"></button>
			</li>
		</template>
	</ul>
</template>
