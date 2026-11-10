{{-- Modal pengajuan pembatalan (D-043, D-044) di halaman pembayaran; logika di orderPage (resources/js/store/shop.js). --}}
<template x-if="order.can_cancel">
	<dialog x-ref="cancelDialog" class="tf-cmodal" aria-labelledby="cm-title" @click="cancelBackdrop">
		<form class="tf-cmodal-sheet" @submit.prevent="requestCancellation">
			<header>
				<span class="tf-cmodal-icon" aria-hidden="true">{{ $site->lineIcon('alert', 22) }}</span>
				<div>
					<h2 id="cm-title">{{ $c['cancel']['title'] }}</h2>
					<p>{{ $c['cancel']['lead'] }}</p>
				</div>
				<button type="button" class="tf-voucher-close" aria-label="{{ $c['cancel']['close'] }}" @click="closeCancel">&times;</button>
			</header>
			<div class="tf-cmodal-body">
				<div class="tf-auth-field">
					<label for="cancel-reason">{{ $c['cancel']['reason'] }}</label>
					<textarea id="cancel-reason" x-model="reason" class="tf-order-reason" rows="4" required maxlength="500" placeholder="{{ $c['cancel']['placeholder'] }}"></textarea>
					<small class="tf-cmodal-count" x-text="reasonCount"></small>
				</div>
				<fieldset class="tf-cmodal-channel">
					<legend>{{ $c['cancel']['channel'] }}</legend>
					<label><input x-model="channel" type="radio" name="cancel-channel" value="whatsapp"><span>{{ $site->lineIcon('headset', 18) }} WhatsApp</span></label>
					<label><input x-model="channel" type="radio" name="cancel-channel" value="email"><span>{{ $site->lineIcon('mail', 18) }} Email</span></label>
				</fieldset>
				<template x-if="cancelError"><p class="tf-shop-issue" role="alert" x-text="cancelError"></p></template>
			</div>
			<footer>
				<button type="button" class="tf-od-outline" @click="closeCancel">{{ $c['cancel']['back'] }}</button>
				<button type="submit" class="tf-btn" :disabled="cancelBusy" :aria-busy="cancelBusy">{{ $c['cancel']['submit'] }}</button>
			</footer>
		</form>
	</dialog>
</template>
