{{-- Pengajuan pembatalan pesanan (D-043, D-044); logika di orderPage (resources/js/store/shop.js). --}}
{{-- $withForm = false: halaman pembayaran; formulirnya ada di modal (order-cancel-modal) dan di sini hanya status pengajuan. --}}
<template x-if="{{ ($withForm ?? true) ? 'showCancel' : 'order.cancellation' }}">
	<section class="tf-od-card">
		<h2 class="tf-od-h2 is-ruled">{{ $c['cancel']['title'] }}</h2>
		<template x-if="order.cancellation">
			<div style="display: contents">
				<p class="tf-shop-note">{{ $c['cancel']['number'] }}: <strong x-text="order.cancellation.request_number"></strong></p>
				<p class="tf-shop-note" role="status" x-text="cancelStatus"></p>
				<template x-if="order.cancellation.rejection_reason"><p class="tf-shop-note" x-text="order.cancellation.rejection_reason"></p></template>
				<template x-if="cancelDone && cancelDone.contact_url"><a :href="cancelDone.contact_url" target="_blank" rel="noopener noreferrer" class="tf-btn tf-order-contact">{{ $c['cancel']['contact'] }}</a></template>
			</div>
		</template>
		@if ($withForm ?? true)
		<template x-if="order.can_cancel">
			<form @submit.prevent="requestCancellation">
				<p class="tf-shop-note">{{ $c['cancel']['lead'] }}</p>
				<div class="tf-auth-field">
					<label for="cancel-reason">{{ $c['cancel']['reason'] }}</label>
					<textarea id="cancel-reason" x-model="reason" class="tf-order-reason" rows="3" required maxlength="500"></textarea>
				</div>
				<fieldset class="tf-order-channel">
					<legend>{{ $c['cancel']['channel'] }}</legend>
					<label><input x-model="channel" type="radio" name="cancel-channel" value="whatsapp"> WhatsApp</label>
					<label><input x-model="channel" type="radio" name="cancel-channel" value="email"> Email</label>
				</fieldset>
				<template x-if="cancelError"><p class="tf-shop-issue" role="alert" x-text="cancelError"></p></template>
				<button type="submit" class="tf-btn" :disabled="cancelBusy">{{ $c['cancel']['submit'] }}</button>
			</form>
		</template>
		@endif
	</section>
</template>
