@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-account-vouchers-data">{{ $site->json([...$c, 'signIn' => $site->route('account')]) }}</script>
	<main class="tf-wrap tf-shop tf-acct" x-data="accountVouchersPage">
		@include('store.partials.account-head', ['title' => $c['title'], 'back' => $c['back'], 'icon' => 'voucher'])

		<div class="tf-voucher-tabs" role="group" aria-label="{{ $c['title'] }}">
			<template x-for="entry in tabs" :key="entry.key">
				<button type="button" :class="tabClass(entry)" :aria-pressed="tabCurrent(entry)" @click="setTab(entry.key)" x-text="entry.label"></button>
			</template>
		</div>

		<template x-if="loading"><div class="tf-acct-skel is-list" role="status"><span class="visually-hidden">{{ $c['loading'] }}</span><div></div><div></div><div></div></div></template>
		<template x-if="failed">
			<div class="tf-auth-notice" role="alert">
				{{ $c['failed'] }}
				<button type="button" class="tf-shop-link" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>
		<template x-if="isEmpty">
			<div class="tf-shop-card tf-account-section tf-acct-emptybox">
				<span class="tf-acct-ico is-lg">@include('store.partials.account-icon', ['name' => 'voucher'])</span>
				<h2>{{ $c['emptyTitle'] }}</h2>
				<p class="tf-shop-note">{{ $c['emptyText'] }}</p>
				<a href="{{ $site->route('vouchers') }}" class="tf-btn tf-account-cta">{{ $c['find'] }}</a>
			</div>
		</template>
		<template x-if="hasItems">
			<ul class="tf-voucher-list tf-voucher-page">
				<template x-for="v in shown" :key="v.code">
					<li :class="cardClass(v)">
						<div>
							<strong x-text="title(v)"></strong>
							<span x-text="description(v)"></span>
							<small x-text="meta(v)"></small>
						</div>
						<a href="{{ $site->route('products') }}" class="tf-btn">{{ $c['use'] }}</a>
					</li>
				</template>
			</ul>
		</template>
		<p class="tf-voucher-mine"><a href="{{ $site->route('vouchers') }}" class="tf-shop-link">{{ $c['find'] }}</a></p>
	</main>
@endsection
