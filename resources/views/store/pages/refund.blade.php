@extends('store.layouts.app')

{{-- Ajukan refund atau retur (D-031, D-097). Tiga langkah dalam satu halaman: cari pesanan, pilih barang dan masalah, konfirmasi.
     Harga, jumlah, dan batas waktu dihitung server; angka di layar hanya perkiraan. Video unboxing tidak diunggah ke situs. --}}
@section('content')
	<script type="application/json" id="tf-refund-data">{{ $site->json(\Illuminate\Support\Arr::only($c, ['orderLine', 'windowOpen', 'windowClosed', 'reasons', 'available', 'unavailable', 'errors', 'tooMany', 'failed', 'copied'])) }}</script>
	<main class="tf-wrap tf-shop tf-refund" x-data="refundPage">
		<div class="tf-shop-card">
			<p class="tf-eyebrow">{{ $c['eyebrow'] }}</p>
			<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
			<p class="tf-shop-lead">{{ $c['lead'] }}</p>
			<h2 class="tf-refund-h">{{ $c['rulesTitle'] }}</h2>
			<ul class="tf-refund-rules">
				@foreach ($c['rules'] as $rule)
					<li>{{ $rule }}</li>
				@endforeach
			</ul>
		</div>

		{{-- Langkah 1: cari pesanan --}}
		<template x-if="step === 'verify'">
			<div class="tf-shop-card tf-refund-reveal">
				<template x-if="notFound">
					<div class="tf-auth-notice" role="alert"><strong>{{ $c['notFoundTitle'] }}</strong><br>{{ $c['notFoundText'] }}</div>
				</template>
				<template x-if="error"><p class="tf-auth-notice" role="alert" x-text="error"></p></template>

				<form @submit.prevent="find">
					<div class="tf-auth-field">
						<label for="rf-number">{{ $c['number'] }}</label>
						<div class="tf-auth-input"><input id="rf-number" x-model="form.order_number" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="{{ $c['numberPlaceholder'] }}"></div>
					</div>
					<div class="tf-auth-field">
						<label for="rf-contact">{{ $c['contact'] }}</label>
						<div class="tf-auth-input"><input id="rf-contact" x-model="form.contact" required maxlength="190" autocomplete="off" spellcheck="false" placeholder="{{ $c['contactPlaceholder'] }}"></div>
					</div>
					<button type="submit" class="tf-btn tf-btn-block" :disabled="busy">{{ $c['verify'] }}</button>
				</form>
				<a href="{{ $site->route('contact') }}" class="tf-auth-link">{{ $c['help'] }}</a>
			</div>
		</template>

		{{-- Langkah 2: barang, masalah, dan persetujuan --}}
		<template x-if="step === 'form'">
			<div class="tf-refund-reveal">
				<div class="tf-shop-card tf-refund-bar">
					<strong x-text="orderLine"></strong>
					<button type="button" class="tf-shop-link" @click="change">{{ $c['change'] }}</button>
				</div>

				<template x-if="!received">
					<div class="tf-shop-card">
						<div class="tf-auth-notice" role="alert"><strong>{{ $c['notReceivedTitle'] }}</strong><br>{{ $c['notReceivedText'] }}</div>
						<a href="{{ $site->route('contact') }}" class="tf-auth-link">{{ $c['help'] }}</a>
					</div>
				</template>

				<template x-if="received">
					<form class="tf-refund-form" @submit.prevent="submit">
						<div class="tf-shop-card">
							<h2>{{ $c['itemsTitle'] }}</h2>
							<p class="tf-shop-note">{{ $c['itemsHint'] }}</p>
			<p class="tf-shop-note" x-show="hasDiscount">{{ $c['discountNote'] }}</p>
							<ul class="tf-refund-items">
								<template x-for="line in lines" :key="line.id">
									<li class="tf-refund-item">
										<div>
											<p class="tf-refund-name"><span x-text="line.name"></span> <span class="tf-refund-variant" x-text="line.variant"></span></p>
											<p class="tf-refund-meta"><span x-text="unitText(line)"></span> &middot; <span x-text="availableText(line)"></span></p>
										</div>
										<div class="tf-refund-qty" role="group" :aria-label="line.name">
											<button type="button" aria-label="{{ $c['qtyDec'] }}" :disabled="busy || line.qty <= 0" @click="dec(line)"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path></svg></button>
											<output aria-live="polite" x-text="line.qty"></output>
											<button type="button" aria-label="{{ $c['qtyInc'] }}" :disabled="busy || line.qty >= line.available" @click="inc(line)"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"></path><path d="M12 5v14"></path></svg></button>
										</div>
									</li>
								</template>
							</ul>
						</div>

						<div class="tf-shop-card">
							<fieldset class="tf-refund-set">
								<legend><h2>{{ $c['reasonTitle'] }}</h2></legend>
								<div class="tf-shop-rates">
									<template x-for="option in reasonOptions" :key="option.value">
										<label :class="reason === option.value ? 'is-on' : ''" :aria-disabled="option.closed">
											<input type="radio" name="reason" :value="option.value" x-model="reason" :disabled="option.closed">
											<span x-text="option.label"></span>
										</label>
									</template>
								</div>
							</fieldset>
							<p class="tf-shop-note" x-show="deadlineText" x-text="deadlineText"></p>

							<div class="tf-auth-field">
								<label for="rf-description">{{ $c['descriptionLabel'] }}</label>
								<textarea id="rf-description" class="tf-refund-text" x-model="description" rows="4" maxlength="1000" required placeholder="{{ $c['descriptionPlaceholder'] }}"></textarea>
								<p class="tf-refund-count" x-text="descriptionCount"></p>
							</div>
						</div>

						<div class="tf-shop-card">
							<fieldset class="tf-refund-set">
								<legend><h2>{{ $c['contactPref'] }}</h2></legend>
								<div class="tf-shop-rates tf-refund-pref">
									<label :class="pref === 'whatsapp' ? 'is-on' : ''"><input type="radio" name="pref" value="whatsapp" x-model="pref"><span>{{ $c['whatsapp'] }}</span></label>
									<label :class="pref === 'email' ? 'is-on' : ''"><input type="radio" name="pref" value="email" x-model="pref"><span>{{ $c['email'] }}</span></label>
								</div>
							</fieldset>

							<label class="tf-refund-terms">
								<input type="checkbox" x-model="terms">
								<span>{{ $c['termsLabel'] }} <a href="{{ $site->route('contact') }}#syarat" target="_blank" rel="noopener">{{ $c['termsLink'] }}</a> {{ $c['termsTail'] }}</span>
							</label>

							<div class="tf-refund-total">
								<div><strong>{{ $c['estimate'] }}</strong><span>{{ $c['estimateNote'] }}</span></div>
								<b x-text="totalText"></b>
							</div>

							<template x-if="error"><p class="tf-auth-notice" role="alert" x-text="error"></p></template>
							<button type="submit" class="tf-btn tf-btn-block tf-refund-submit" :disabled="submitDisabled">{{ $c['submit'] }}</button>
						</div>
					</form>
				</template>
			</div>
		</template>

		{{-- Langkah 3: pengajuan diterima, lanjut ke CS --}}
		<template x-if="step === 'done'">
			<div class="tf-shop-card tf-refund-done tf-refund-reveal" role="status">
				<h2>{{ $c['doneTitle'] }}</h2>
				<p class="tf-shop-note">{{ $c['doneText'] }}</p>
				<p class="tf-refund-label">{{ $c['refundNumber'] }}</p>
				<p class="tf-refund-number" x-text="done.refund_number"></p>
				<div class="tf-refund-actions">
					<a class="tf-btn" :href="done.contact_url" target="_blank" rel="noopener noreferrer" x-show="done.contact_url">{{ $c['contactCs'] }}</a>
					<button type="button" class="tf-shop-link" @click="copyNumber">{{ $c['copy'] }}</button>
				</div>
				<h2 class="tf-refund-h">{{ $c['nextTitle'] }}</h2>
				<ol class="tf-refund-next">
					@foreach ($c['next'] as $step)
						<li>{{ $step }}</li>
					@endforeach
				</ol>
				<button type="button" class="tf-shop-link" @click="another">{{ $c['another'] }}</button>
			</div>
		</template>
	</main>
@endsection
