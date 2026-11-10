{{-- Pilih voucher (maksimal 2) di keranjang dan checkout. Logika: resources/js/store/voucherPicker.js. $v = teks voucher (pages.checkout.voucher). --}}
<dialog x-ref="voucherDialog" class="tf-voucher-dialog tf-vpick" aria-labelledby="vp-title" @click="voucherBackdrop">
	<div class="tf-vpick-sheet">
		<header>
			<h2 id="vp-title">{{ $v['listTitle'] }}</h2>
			<button type="button" class="tf-voucher-close" aria-label="{{ $v['close'] }}" @click="closeVouchers">&times;</button>
		</header>
		<div class="tf-vpick-body">
			<p class="tf-vpick-hint"><strong x-text="pickCountText"></strong> {{ $v['stackNote'] }}</p>
			<form class="tf-vpick-code" @submit.prevent="applyCode">
				<label class="tf-sr" for="vp-code">{{ $v['codeLabel'] }}</label>
				<input id="vp-code" x-model="voucherCode" maxlength="40" autocomplete="off" autocapitalize="characters" placeholder="{{ $v['codePlaceholder'] }}">
				<button type="submit" class="tf-btn" :disabled="voucherBusy">{{ $v['add'] }}</button>
			</form>
			<template x-if="voucherError"><p class="tf-shop-issue" role="alert" x-text="voucherError"></p></template>
			<template x-if="vouchersLoaded && !vouchers.length"><p class="tf-shop-note">{{ $v['empty'] }}</p></template>
			<ul class="tf-vpick-list">
				<template x-for="v in vouchers" :key="v.code">
					<li :class="pickClass(v)">
						<label>
							<input type="checkbox" :checked="isPicked(v)" :disabled="pickBlocked(v)" @change="togglePick(v)">
							<span class="tf-vpick-text">
								<strong x-text="voucherTitle(v)"></strong>
								<small x-text="voucherMeta(v)"></small>
								<em :class="pickNoteClass(v)" x-text="pickNote(v)"></em>
							</span>
							<span class="tf-vpick-tag" :hidden="!v.stackable">{{ $v['stackTag'] }}</span>
						</label>
					</li>
				</template>
			</ul>
		</div>
		<footer>
			<a href="{{ $site->route('vouchers') }}" class="tf-shop-link">{{ $v['more'] }}</a>
			<button type="button" class="tf-btn" :disabled="voucherBusy" @click="submitPick" x-text="applyLabel"></button>
		</footer>
	</div>
</dialog>
