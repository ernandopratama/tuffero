@extends('store.layouts.app', ['bottomNav' => false])

@php
	$cartWords = $pg['cart'];
@endphp

{{-- Tata letak FigCraft "04 Checkout" dan "04b Checkout / Modal Pengiriman". Pembayaran tetap lewat Snap (D-096): metode dipilih di jendela Midtrans setelah pesanan dibuat. --}}
@section('content')
	<script type="application/json" id="tf-checkout-data">{{ $site->json([...\Illuminate\Support\Arr::only($c, ['errors', 'tooMany', 'failed', 'lead', 'leadSignedIn', 'submit', 'submitting', 'shippingPending', 'saveLabelDefault', 'voucher', 'search', 'map', 'etd', 'confirmFirst', 'addressSaved', 'variant', 'qty', 'each', 'itemsTitle', 'productsTotal', 'shippingTotal']), 'customer' => $storeUser && ! $storeRole, 'otp' => config('services.whatsapp.otp_required') && ! ($storeUser && ! $storeRole) ? [...$c['otp'], 'siteKey' => config('services.turnstile.site_key')] : null]) }}</script>
	<main class="tf-co" x-data="checkoutPage">
		@include('store.partials.flow-steps', ['steps' => $c['flow'], 'label' => $c['flowLabel'], 'current' => 1, 'variant' => 'stack'])

		<div class="tf-wrap tf-co-wrap tf-checkout">
			<h1 class="tf-sr">{{ $c['title'] }}</h1>

			<template x-if="loading"><p class="tf-co-state" role="status">{{ $cartWords['loading'] }}</p></template>
			<template x-if="loadFailed">
				<div class="tf-co-state" role="alert">
					<p>{{ $c['failed'] }}</p>
					<button type="button" class="tf-btn" @click="loadCart">{{ $cartWords['retry'] }}</button>
				</div>
			</template>
			<template x-if="empty">
				<div class="tf-co-state">
					<h2>{{ $cartWords['empty'] }}</h2>
					<a href="{{ $site->route('products') }}" class="tf-btn">{{ $cartWords['browse'] }}</a>
				</div>
			</template>
			<template x-if="blocked">
				<div class="tf-co-state" role="alert">
					<h2>{{ $c['cartProblem'] }}</h2>
					<a href="{{ $site->route('cart') }}" class="tf-btn">{{ $c['backToCart'] }}</a>
				</div>
			</template>

			<template x-if="canFill">
				<form class="tf-co-grid" novalidate @submit.prevent="submit">
					<div class="tf-co-main">
						<section class="tf-co-card" id="co-ship" aria-labelledby="co-ship-title">
							<header class="tf-co-head">
								<span class="tf-co-head-icon">{{ $site->lineIcon('truck', 20) }}</span>
								<h2 id="co-ship-title">{{ $c['shipTitle'] }}</h2>
								<template x-if="addressConfirmed"><button type="button" class="tf-co-head-link" @click="editAddress">{{ $c['editData'] }}</button></template>
							</header>

							<div class="tf-co-edit" :hidden="addressConfirmed" :aria-busy="confirming">
								{{-- Selimut loading satu halaman (kecuali navbar) berisi modal konfirmasi alamat; tampil bersama saat tarif ongkir dimuat. Dipindah ke body karena leluhur bertransformasi menjebak position: fixed. --}}
								<template x-teleport="body">
								<div class="tf-busy" :class="confirming ? 'is-busy' : ''" @keydown.escape.window="onEscape">
									<div class="tf-confirm" role="dialog" aria-modal="true" aria-labelledby="co-confirm-title">
										<h2 id="co-confirm-title">{{ $c['confirmTitle'] }}</h2>
										<p class="tf-confirm-lead">{{ $c['confirmLead'] }}</p>
										<div class="tf-confirm-card">
											<p class="tf-confirm-name" x-text="form.customer_name"></p>
											<p x-text="phoneText"></p>
											<template x-for="line in addressSummary" :key="line"><p x-text="line"></p></template>
											<p x-text="form.customer_email"></p>
										</div>
										<div class="tf-confirm-status" role="status">
											<template x-if="confirmChecking"><span class="tf-confirm-wait">@include('store.partials.dot-wave') {{ $c['confirmChecking'] }}</span></template>
											<template x-if="confirmDone"><span class="tf-confirm-ok">{{ $site->lineIcon('shieldCheck', 16) }} {{ $c['confirmReady'] }}</span></template>
											<template x-if="confirmFailed"><span class="tf-confirm-bad">{{ $c['courierFailed'] }}</span></template>
										</div>
										<div class="tf-confirm-actions">
											<button type="button" class="tf-confirm-edit" @click="editAddress">{{ $c['confirmEdit'] }}</button>
											<button type="button" class="tf-btn" id="co-confirm-yes" :disabled="accepting" @click="acceptAddress">{{ $c['confirmYes'] }}</button>
										</div>
									</div>
								</div>
								</template>
								<p class="tf-co-hint" x-text="leadText">{{ $c['lead'] }}</p>
								<div class="tf-shop-fields">
									<div class="tf-auth-field is-wide" :class="fieldClass('customer_name')">
										<label for="co-name">{{ $c['name'] }}</label>
										<div class="tf-auth-input"><input id="co-name" x-model="form.customer_name" autocomplete="name" maxlength="120" :aria-invalid="invalid('customer_name')" @input="clearError('customer_name')"></div>
										<template x-if="invalid('customer_name')"><p role="alert" x-text="errorFor('customer_name')"></p></template>
									</div>
									<div class="tf-auth-field" :class="fieldClass('customer_email')">
										<label for="co-email">{{ $c['email'] }}</label>
										<div class="tf-auth-input"><input id="co-email" x-model="form.customer_email" type="email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false" maxlength="190" :aria-invalid="invalid('customer_email')" @input="clearError('customer_email')"></div>
										<template x-if="invalid('customer_email')"><p role="alert" x-text="errorFor('customer_email')"></p></template>
									</div>
									<div class="tf-auth-field" :class="fieldClass('customer_phone')">
										<label for="co-phone">{{ $c['phone'] }}</label>
										<div class="tf-auth-input"><span aria-hidden="true">+62</span><input id="co-phone" x-model="form.customer_phone" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="20" placeholder="812-3456-7890" :aria-invalid="invalid('customer_phone')" @input="clearError('customer_phone')"></div>
										<template x-if="invalid('customer_phone')"><p role="alert" x-text="errorFor('customer_phone')"></p></template>
									</div>
								</div>

								<h3 class="tf-co-sub">{{ $c['addressTitle'] }}</h3>
								<template x-if="addresses.length">
									<div class="tf-auth-field">
										<label for="co-saved">{{ $c['savedAddress'] }}</label>
										<div class="tf-auth-input"><select id="co-saved" :value="addressId" @change="chooseAddress">
											<template x-for="address in addresses" :key="address.id"><option :value="address.id" :selected="address.id === addressId" x-text="addressLabel(address)"></option></template>
											<option value="" :selected="addressId === ''">{{ $c['newAddress'] }}</option>
										</select></div>
									</div>
								</template>
								<div class="tf-shop-fields">
									<div class="tf-auth-field is-wide" :class="fieldClass('street')">
										<label for="co-street">{{ $c['street'] }}</label>
										<div class="tf-auth-input"><input id="co-street" x-model="form.street" autocomplete="street-address" maxlength="255" aria-describedby="co-street-hint" :aria-invalid="invalid('street')" @input="clearError('street')"></div>
										<p id="co-street-hint" class="tf-field-hint">{{ $c['streetHint'] }}</p>
										<template x-if="invalid('street')"><p role="alert" x-text="errorFor('street')"></p></template>
									</div>
									@include('store.partials.region-combo', ['field' => 'province', 'label' => $c['province'], 'prefix' => 'co', 'words' => $c])
									@include('store.partials.region-combo', ['field' => 'district', 'label' => $c['district'], 'prefix' => 'co', 'words' => $c])
									@include('store.partials.region-combo', ['field' => 'city', 'label' => $c['city'], 'prefix' => 'co', 'words' => $c])
									<div class="tf-auth-field" :class="fieldClass('postal_code')">
										<label for="co-postal">{{ $c['postal'] }}</label>
										<div class="tf-auth-input"><input id="co-postal" x-model="form.postal_code" list="co-postal-list" autocomplete="postal-code" inputmode="numeric" maxlength="5" :aria-invalid="invalid('postal_code')" @input="clearError('postal_code')"></div>
										<datalist id="co-postal-list"><template x-for="code in postalOptions" :key="code"><option :value="code"></option></template></datalist>
										<template x-if="invalid('postal_code')"><p role="alert" x-text="errorFor('postal_code')"></p></template>
									</div>
								</div>
								@include('store.partials.address-map', ['prefix' => 'co', 'words' => $c])
								<template x-if="canSaveAddress">
									<div style="display: contents">
										<label class="tf-account-check"><input x-model="saveAddress" type="checkbox"> {{ $c['saveAddress'] }}</label>
										<template x-if="saveAddress">
											<div class="tf-auth-field">
												<label for="co-save-label">{{ $c['saveLabel'] }}</label>
												<div class="tf-auth-input"><input id="co-save-label" x-model="saveLabel" maxlength="50"></div>
											</div>
										</template>
									</div>
								</template>
								<div class="tf-address-confirm">
									<button type="button" id="co-confirm" class="tf-btn" :disabled="confirming" @click="confirmAddress">{{ $c['confirmAddress'] }}</button>
									<p class="tf-field-hint">{{ $c['confirmHint'] }}</p>
								</div>
							</div>

							<template x-if="addressConfirmed">
								<div class="tf-co-confirmed" aria-live="polite">
									<div class="tf-co-tile">
										<div class="tf-co-tile-top">
											<span class="tf-co-pill" x-text="addressBadge"></span>
											<span class="tf-co-tile-meta" x-text="phoneText"></span>
										</div>
										<p class="tf-co-tile-name" x-text="form.customer_name"></p>
										<template x-for="line in addressSummary" :key="line"><p class="tf-co-tile-text" x-text="line"></p></template>
										<p class="tf-co-tile-text" x-text="form.customer_email"></p>
									</div>

									<button type="button" class="tf-co-tile tf-co-courier" id="co-courier" :class="courierTileClass" @click="openShipping" aria-haspopup="dialog">
										<span class="tf-co-tile-top">
											<span class="tf-co-tile-meta">{{ $site->lineIcon('truck', 14) }} {{ $c['courierLabel'] }}</span>
											<span class="tf-co-tile-link">{{ $c['courierChange'] }} {{ $site->lineIcon('chevronDown', 14) }}</span>
										</span>
										<template x-if="ratesLoading"><span class="tf-co-tile-text" role="status">{{ $c['courierLoading'] }}</span></template>
										<template x-if="!ratesLoading && ratesFailed"><span class="tf-co-tile-text is-issue">{{ $c['courierFailed'] }} {{ $c['courierRetry'] }}</span></template>
										<template x-if="noRates"><span class="tf-co-tile-text">{{ $c['courierNone'] }}</span></template>
										<template x-if="!ratesLoading && !ratesFailed && hasRates && !selected">
											<span style="display: contents">
												<span class="tf-co-tile-name">{{ $c['courierChoose'] }}</span>
												<span class="tf-co-tile-text">{{ $c['courierChooseHint'] }}</span>
											</span>
										</template>
										<template x-if="selected">
											<span style="display: contents">
												<span class="tf-co-tile-name" x-text="selected.label"></span>
												<template x-if="etdText(selected)"><span class="tf-co-tile-text">{{ $site->lineIcon('clock', 14) }} <span x-text="etdText(selected)"></span></span></template>
												<span class="tf-co-tile-fee" x-text="rupiah(selected.fee)"></span>
											</span>
										</template>
									</button>

									{{-- Verifikasi WhatsApp tamu (D-051, D-093). Tampil hanya bila WHATSAPP_OTP_REQUIRED aktif dan pengunjung belum masuk sebagai member. --}}
									<template x-if="otpRequired">
										<div class="tf-co-tile tf-co-otp" id="co-otp" aria-labelledby="co-otp-title">
											<span class="tf-co-tile-top">
												<span class="tf-co-tile-meta" id="co-otp-title">{{ $site->lineIcon('shieldCheck', 14) }} {{ $c['otp']['title'] }}</span>
											</span>
											<div aria-live="polite" style="display: contents">
												<template x-if="otpIsVerified"><p class="tf-confirm-ok">{{ $site->lineIcon('shieldCheck', 16) }} {{ $c['otp']['verified'] }}</p></template>
												<template x-if="otpNeedsLogin">
													<span style="display: contents">
														<p class="tf-co-tile-text">{{ $c['otp']['loginRequired'] }}</p>
														<a href="{{ $site->route('account') }}" class="tf-btn">{{ $c['otp']['signIn'] }}</a>
													</span>
												</template>
												<template x-if="otpShowForm">
													<span style="display: contents">
														<p class="tf-co-tile-text" x-text="otpLead"></p>
														<template x-if="otpSent">
															<div class="tf-auth-field" :class="otpFieldClass">
																<label for="co-otp-code">{{ $c['otp']['code'] }}</label>
																<div class="tf-auth-input"><input id="co-otp-code" :value="otpCode" @input="onOtpInput" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="6" aria-describedby="co-otp-hint" :aria-invalid="otpInvalid" :disabled="otpBusy"></div>
																<p id="co-otp-hint" class="tf-field-hint">{{ $c['otp']['codeHint'] }}</p>
															</div>
														</template>
														<div id="co-turnstile"></div>
														<button type="button" class="tf-co-ghost" :disabled="!otpCanSend" @click="sendOtp" x-text="otpSendText"></button>
													</span>
												</template>
												<template x-if="otpBusy"><span class="tf-confirm-wait" role="status">@include('store.partials.dot-wave') <span x-text="otpBusyText"></span></span></template>
												<template x-if="otpError"><p class="tf-shop-issue" role="alert" x-text="otpError"></p></template>
											</div>
										</div>
									</template>
								</div>
							</template>
							<template x-if="invalid('courier')"><p class="tf-shop-issue" role="alert" x-text="errorFor('courier')"></p></template>
						</section>

						{{-- Kartu Metode Pembayaran disembunyikan sementara (Midtrans tetap dipakai setelah pesanan dibuat); hapus tanda komentar ini untuk menampilkannya lagi.
						<section class="tf-co-card" aria-labelledby="co-pay-title">
							<header class="tf-co-head">
								<span class="tf-co-head-icon">{{ $site->lineIcon('card', 20) }}</span>
								<h2 id="co-pay-title">{{ $c['payTitle'] }}</h2>
								<span class="tf-co-pill">{{ $c['payBadge'] }}</span>
							</header>
							<div class="tf-co-method">
								<span class="tf-co-radio is-on" aria-hidden="true"></span>
								<div class="tf-co-method-body">
									<p class="tf-co-method-name">{{ $c['payName'] }}</p>
									<p class="tf-co-method-text">{{ $c['payText'] }}</p>
									<div class="tf-co-method-note">
										<small>{{ $c['payNote'] }}</small>
										<strong>{{ $c['payNoteSub'] }}</strong>
									</div>
								</div>
								<span class="tf-co-chip">Midtrans</span>
							</div>
							<p class="tf-co-secure">{{ $site->lineIcon('shieldCheck', 16) }} {{ $c['paySecure'] }}</p>
						</section>
						--}}

						<section class="tf-co-card" aria-labelledby="co-items-title">
							<header class="tf-co-head">
								<span class="tf-co-head-icon">{{ $site->lineIcon('basket', 20) }}</span>
								<h2 id="co-items-title" x-text="itemsTitle"></h2>
							</header>
							<ul class="tf-co-items">
								<template x-for="item in lines" :key="item.sku">
									<li>
										<span class="tf-co-photo">
											<template x-if="item.photo"><img :src="photoUrl(item)" alt="" width="64" height="64" loading="lazy" decoding="async"></template>
										</span>
										<span class="tf-co-item-info">
											<span class="tf-co-item-name" x-text="item.product_name"></span>
											<template x-if="hasVariant(item)"><span class="tf-co-item-variant" x-text="variantText(item)"></span></template>
											<span class="tf-co-item-qty" x-text="qtyText(item)"></span>
										</span>
										<span class="tf-co-item-price">
											<strong x-text="rupiah(item.line_total)"></strong>
											<small x-text="eachText(item)"></small>
										</span>
									</li>
								</template>
							</ul>
						</section>
					</div>

					<aside class="tf-co-side">
						<div class="tf-co-card tf-co-bill">
							<header class="tf-co-head is-plain">
								<h2>{{ $c['billTitle'] }}</h2>
							</header>
							<dl class="tf-co-rows">
								<div><dt x-text="productsTotalText"></dt><dd x-text="subtotalText"></dd></div>
								<template x-if="hasSaving"><div class="is-note"><dt>{{ $c['saving'] }}</dt><dd x-text="savingText"></dd></div></template>
								<div><dt x-text="shippingText"></dt><dd x-text="feeText"></dd></div>
								<template x-for="a in applied" :key="a.code">
									<div class="is-minus">
										<dt>{{ $site->lineIcon('gift', 14) }} <span x-text="appliedLine(a)"></span> <button type="button" class="tf-co-inline" @click="removeVoucher(a)">{{ $c['voucher']['remove'] }}</button></dt>
										<dd x-text="appliedText(a)"></dd>
									</div>
								</template>
							</dl>
							<button type="button" class="tf-co-voucher" aria-haspopup="dialog" @click="openVouchers">{{ $site->lineIcon('gift', 16) }} <span x-text="voucherButtonText"></span></button>
							<div class="tf-co-total">
								<span>{{ $c['grandTotal'] }}</span>
								<strong x-text="totalText"></strong>
							</div>
							<template x-if="formError"><p class="tf-shop-issue" role="alert" x-text="formError"></p></template>
							<button type="submit" class="tf-btn tf-btn-block tf-co-submit" :disabled="submitting" :aria-busy="submitting">
								{{ $site->lineIcon('lock', 16) }}<span x-text="submitText"></span>{{ $site->lineIcon('arrowRight', 18) }}
							</button>
							<div class="tf-co-links">
								<button type="button" class="tf-co-back" @click="backToEdit">{{ $site->lineIcon('arrowLeft', 14) }} {{ $c['backToEdit'] }}</button>
								<a href="{{ $site->route('cart') }}" class="tf-co-back">{{ $c['backToCart'] }}</a>
							</div>
							<p class="tf-co-note">{{ $c['serverNote'] }}</p>
						</div>
						<div class="tf-co-help">
							<span class="tf-co-help-icon">{{ $site->lineIcon('headset', 18) }}</span>
							<div><strong>{{ $c['helpTitle'] }}</strong><small>{{ $c['helpText'] }}</small></div>
							<a href="{{ $site->route('contact') }}" class="tf-co-help-link">{{ $c['helpCta'] }}</a>
						</div>
					</aside>
				</form>
			</template>
		</div>

		<dialog x-ref="shipDialog" class="tf-co-dialog tf-co-ship-dialog" aria-labelledby="co-ship-dialog-title" @click="shippingBackdrop" @close="clearDraft">
			<div class="tf-co-dialog-sheet">
				<header class="tf-co-dialog-head">
					<div>
						<h2 id="co-ship-dialog-title">{{ $site->lineIcon('truck', 20) }} {{ $c['modalTitle'] }}</h2>
						<p>{{ $c['modalLead'] }}</p>
					</div>
					<button type="button" class="tf-co-dialog-close" aria-label="{{ $c['modalClose'] }}" @click="closeShipping">{{ $site->lineIcon('close', 20) }}</button>
				</header>
				<div class="tf-co-dialog-body">
					<template x-if="ratesLoading"><p class="tf-shop-note" role="status">{{ $c['courierLoading'] }}</p></template>
					<template x-if="!ratesLoading && ratesFailed"><p class="tf-shop-issue" role="alert">{{ $c['courierFailed'] }} <button type="button" class="tf-shop-link" @click="retryRates">{{ $c['courierRetry'] }}</button></p></template>
					<template x-if="noRates"><p class="tf-shop-note">{{ $c['courierNone'] }}</p></template>
					<div class="tf-co-options" role="radiogroup" aria-labelledby="co-ship-dialog-title">
						<template x-for="rate in rates" :key="rateKey(rate)">
							<label class="tf-co-option">
								<input type="radio" name="co-courier-draft" class="tf-sr" :checked="isDraft(rate)" @change="pickDraft(rate)">
								<span class="tf-co-radio" aria-hidden="true"></span>
								<span class="tf-co-option-logo">
									<template x-if="courierLogo(rate)"><img :src="courierLogo(rate)" alt="" width="56" height="32" loading="lazy" decoding="async"></template>
									<template x-if="!courierLogo(rate)"><b x-text="courierInitials(rate)"></b></template>
								</span>
								<span class="tf-co-option-info">
									<span class="tf-co-option-name" x-text="rate.label"></span>
									<template x-if="etdText(rate)"><small>{{ $site->lineIcon('clock', 14) }}<span x-text="etdText(rate)"></span></small></template>
								</span>
								<strong class="tf-co-option-fee" x-text="rupiah(rate.fee)"></strong>
							</label>
						</template>
					</div>
				</div>
				<footer class="tf-co-dialog-foot">
					<button type="button" class="tf-co-ghost" @click="closeShipping">{{ $c['modalCancel'] }}</button>
					<button type="button" class="tf-btn" :disabled="!draft" @click="applyShipping">{{ $c['modalApply'] }} {{ $site->lineIcon('check', 16) }}</button>
				</footer>
			</div>
		</dialog>

		@include('store.partials.voucher-picker', ['v' => $c['voucher']])
	</main>
@endsection
