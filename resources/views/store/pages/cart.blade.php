@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-cart-data">{{ $site->json(['customer' => $storeUser && ! $storeRole, ...\Illuminate\Support\Arr::only($c, ['insufficient', 'removeLabel', 'removed', 'updateFailed', 'selectLabel', 'selectAll', 'removeSelected', 'unavailableTitle', 'total', 'count', 'variant', 'checkoutCount', 'off', 'offFixed', 'min', 'upTo', 'until', 'voucherHint', 'voucherCount', 'voucherEmptyMember', 'voucherEmptyGuest', 'voucherOpen', 'voucherAppliedBar', 'voucher', 'errors', 'tooMany'])]) }}</script>
	<main class="tf-wrap tf-shop tf-cartp" x-data="cartPage">
		<nav class="tf-cartp-crumbs" aria-label="{{ $c['crumbs'] }}">
			<a href="/">{{ $c['home'] }}</a>
			<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
			<span aria-current="page">{{ $c['crumb'] }}</span>
		</nav>
		<div class="tf-cartp-title">
			<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
			<template x-if="filled"><span class="tf-cartp-count" x-text="countText"></span></template>
		</div>

		<template x-if="loading"><p class="tf-shop-note" role="status">{{ $c['loading'] }}</p></template>
		<template x-if="failed">
			<div class="tf-cat-empty" role="alert">
				<p>{{ $c['loadFailed'] }}</p>
				<button type="button" class="tf-btn" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>
		<template x-if="empty">
			<div class="tf-cartp-empty">
				@include('store.partials.empty-cart-art')
				<h2>{{ $c['empty'] }}</h2>
				<p>{{ $c['emptyHint'] }}</p>
				<a href="{{ $site->route('products') }}" class="tf-btn">{{ $c['browse'] }}</a>
			</div>
		</template>

		<template x-if="filled">
			<div class="tf-cartp-body">
				{{-- Kepala kolom (desktop). Checkbox di keranjang hanya untuk memilih produk yang mau dihapus; checkout tetap seluruh keranjang. --}}
				<div class="tf-cartp-head" x-show="active.length">
					<label class="tf-cartp-check">
						<input type="checkbox" :checked="allSelected" :disabled="busy" @change="toggleAll" :aria-label="selectAllText">
					</label>
					<span>{{ $c['colProduct'] }}</span>
					<span>{{ $c['colPrice'] }}</span>
					<span>{{ $c['colQty'] }}</span>
					<span>{{ $c['colTotal'] }}</span>
					<span>{{ $c['colAction'] }}</span>
				</div>

				<section class="tf-cartp-group" x-show="active.length" aria-labelledby="tf-cartp-store">
					<header class="tf-cartp-group-head">
						<label class="tf-cartp-check">
							<input type="checkbox" :checked="allSelected" :disabled="busy" @change="toggleAll" :aria-label="selectAllText">
						</label>
						<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9 4.5 4h15L21 9"></path><path d="M3 9h18v1a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0Z"></path><path d="M5 12.5V20h14v-7.5"></path><path d="M10 20v-5h4v5"></path></svg>
						<h2 id="tf-cartp-store">{{ $c['store'] }}</h2>
						<span class="tf-cartp-tag">{{ $c['warehouse'] }}</span>
					</header>
					<ul class="tf-cartp-list">
						<template x-for="item in active" :key="item.sku">
							<li class="tf-cartp-row" :class="item.issue ? 'has-issue' : ''">
								<label class="tf-cartp-check">
									<input type="checkbox" :checked="isSelected(item)" :disabled="busy" @change="toggle(item)" :aria-label="selectLabel(item)">
								</label>
								<div class="tf-cartp-product">
									<div class="tf-cartp-photo">
										<template x-if="item.photo"><img :src="photoUrl(item)" alt="" width="80" height="80" loading="lazy"></template>
									</div>
									<div class="tf-cartp-info">
										<h3 x-text="item.product_name"></h3>
										<template x-if="hasVariant(item)"><p class="tf-cartp-variant" x-text="variantText(item)"></p></template>
										<template x-if="hasOff(item)"><em class="tf-off" x-text="offText(item)"></em></template>
										<template x-if="item.issue === 'insufficient_stock'"><p class="tf-shop-issue" role="alert" x-text="insufficient(item)"></p></template>
									</div>
								</div>
								<div class="tf-cartp-price">
									<template x-if="hasOff(item)"><s x-text="rupiah(item.normal)"></s></template>
									<span x-text="rupiah(item.price)"></span>
								</div>
								<div class="tf-cartp-qty">
									<div class="tf-pd-stepper" role="group" aria-label="{{ $c['qty'] }}">
										<button type="button" aria-label="{{ $c['qtyDec'] }}" :disabled="busy || item.quantity <= 1" @click="dec(item)"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path></svg></button>
										<span aria-live="polite" x-text="item.quantity"></span>
										<button type="button" aria-label="{{ $c['qtyInc'] }}" :disabled="busy || item.quantity >= (item.max_quantity || 50)" @click="inc(item)"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg></button>
									</div>
								</div>
								<strong class="tf-cartp-line" x-text="rupiah(item.line_total)"></strong>
								<div class="tf-cartp-act">
									<button type="button" class="tf-bin" :class="isRemoving(item) ? 'is-throwing' : ''" :disabled="busy" :aria-label="removeLabel(item)" title="{{ $c['remove'] }}" @click="remove(item)">@include('store.partials.bin-icon')</button>
								</div>
							</li>
						</template>
					</ul>
					<p class="tf-cartp-ship">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"></path><path d="M15 18H9"></path><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"></path><circle cx="17" cy="18" r="2"></circle><circle cx="7" cy="18" r="2"></circle></svg>
						{{ $c['shippingLater'] }} {{ $c['oneWarehouse'] }}
					</p>
				</section>

				<template x-if="gone.length">
					<section class="tf-cartp-group is-gone" aria-labelledby="tf-cartp-gone">
						<header class="tf-cartp-group-head">
							<h2 id="tf-cartp-gone" x-text="unavailableText"></h2>
							<button type="button" class="tf-cartp-remove is-danger" :disabled="busy" @click="removeMany(gone)">{{ $c['clearUnavailable'] }}</button>
						</header>
						<ul class="tf-cartp-list">
							<template x-for="item in gone" :key="item.sku">
								<li class="tf-cartp-row">
									<span class="tf-cartp-check" aria-hidden="true"></span>
									<div class="tf-cartp-product">
										<div class="tf-cartp-photo">
											<template x-if="item.photo"><img :src="photoUrl(item)" alt="" width="80" height="80" loading="lazy"></template>
											<span class="tf-cartp-soldout">{{ $c['soldOut'] }}</span>
										</div>
										<div class="tf-cartp-info">
											<h3 x-text="item.product_name"></h3>
											<template x-if="hasVariant(item)"><p class="tf-cartp-variant" x-text="variantText(item)"></p></template>
											<p class="tf-shop-issue">{{ $c['unavailable'] }}</p>
										</div>
									</div>
									<div class="tf-cartp-price"><span x-text="rupiah(item.price)"></span></div>
									<div class="tf-cartp-qty" aria-hidden="true">-</div>
									<span class="tf-cartp-line" aria-hidden="true">-</span>
									<div class="tf-cartp-act">
										<button type="button" class="tf-bin" :class="isRemoving(item) ? 'is-throwing' : ''" :disabled="busy" :aria-label="removeLabel(item)" title="{{ $c['remove'] }}" @click="remove(item)">@include('store.partials.bin-icon')</button>
									</div>
								</li>
							</template>
						</ul>
					</section>
				</template>

				{{-- Bar checkout menempel di bawah layar selama daftar produk digulir. --}}
				<div class="tf-cartp-bar">
					{{-- Voucher: kosong menuju halaman Voucher untuk klaim; ada voucher membuka pilihan (modal, maksimal 2). Dipakai di checkout. --}}
					<template x-if="!hasVouchers">
						<a href="{{ $site->route('vouchers') }}" class="tf-cartp-voucher">
							{{ $site->lineIcon('ticket', 20) }}
							<strong>{{ $c['voucherTitle'] }}</strong>
							<span x-text="voucherHint"></span>
							{{ $site->lineIcon('chevronRight', 16) }}
						</a>
					</template>
					<template x-if="hasVouchers">
						<button type="button" class="tf-cartp-voucher" aria-haspopup="dialog" @click="openVouchers">
							{{ $site->lineIcon('ticket', 20) }}
							<strong>{{ $c['voucherTitle'] }}</strong>
							<em class="tf-cartp-voucher-count" x-text="voucherCountText"></em>
							<span x-text="voucherBarText"></span>
							{{ $site->lineIcon('chevronRight', 16) }}
						</button>
					</template>
					<div class="tf-cartp-bar-main">
						<div class="tf-cartp-bulk" x-show="active.length">
							<label class="tf-cartp-check is-labelled">
								<input type="checkbox" :checked="allSelected" :disabled="busy" @change="toggleAll">
								<span x-text="selectAllText"></span>
							</label>
							<button type="button" class="tf-cartp-remove" :disabled="busy || !selectedItems.length" @click="removeMany(selectedItems)" x-text="removeSelectedText"></button>
						</div>
						<div class="tf-cartp-total">
							<p><span x-text="totalText"></span> <strong x-text="payableText"></strong></p>
							<template x-if="hasSaving"><p class="tf-cartp-saving">{{ $c['savingShort'] }} <b x-text="savingText"></b></p></template>
						</div>
						<template x-if="cart.can_checkout"><a href="{{ $site->route('checkout') }}" class="tf-btn tf-cartp-cta" x-text="checkoutText"></a></template>
						<template x-if="!cart.can_checkout"><button type="button" class="tf-btn tf-cartp-cta" disabled aria-describedby="tf-cartp-blocked" x-text="checkoutText"></button></template>
					</div>
					<template x-if="!cart.can_checkout"><p class="tf-shop-issue tf-cartp-msg" id="tf-cartp-blocked" x-text="selectedItems.length ? @js($c['blocked']) : @js($c['pickToCheckout'])"></p></template>
				</div>

				<a href="{{ $site->route('products') }}" class="tf-auth-link tf-cartp-more">{{ $c['continue'] }}</a>
			</div>
		</template>
		@include('store.partials.voucher-picker', ['v' => $c['voucher']])
	</main>
@endsection
