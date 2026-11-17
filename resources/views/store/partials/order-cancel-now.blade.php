{{-- Batalkan pesanan yang belum dibayar: langsung batal tanpa persetujuan (D-138). Logika di orderPage (resources/js/store/shop.js). --}}
<template x-if="order.can_cancel_now">
	<div style="display: contents">
		<button type="button" class="tf-od-outline" aria-haspopup="dialog" @click="openCancelNow">{{ $c['cancel']['now']['button'] }}</button>
		<dialog x-ref="cancelNowDialog" class="tf-cmodal" aria-labelledby="cn-title" @click="cancelNowBackdrop">
			<div class="tf-cmodal-sheet">
				<header>
					<span class="tf-cmodal-icon" aria-hidden="true">{{ $site->lineIcon('alert', 22) }}</span>
					<div>
						<h2 id="cn-title">{{ $c['cancel']['now']['title'] }}</h2>
						<p>{{ $c['cancel']['now']['lead'] }}</p>
					</div>
					<button type="button" class="tf-voucher-close" aria-label="{{ $c['cancel']['now']['close'] }}" @click="closeCancelNow">&times;</button>
				</header>
				<div class="tf-cmodal-body">
					<template x-if="cancelNowError"><p class="tf-shop-issue" role="alert" x-text="cancelNowError"></p></template>
				</div>
				<footer>
					<button type="button" class="tf-od-outline" @click="closeCancelNow">{{ $c['cancel']['now']['back'] }}</button>
					<button type="button" class="tf-btn" :disabled="cancelNowBusy" :aria-busy="cancelNowBusy" @click="cancelNow">{{ $c['cancel']['now']['confirm'] }}</button>
				</footer>
			</div>
		</dialog>
	</div>
</template>
