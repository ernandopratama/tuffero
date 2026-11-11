@extends('store.layouts.app')

{{-- Satu halaman, beberapa tampilan menurut status pesanan dari server:
     menunggu bayar = FigCraft "11 Pembayaran" (Snap, D-075), Snap gagal = "11c", kedaluwarsa = "11b",
     dibayar/diproses/dikirim = "05 Status Pesanan", selesai = "06 Pesanan Selesai". --}}
@section('content')
	<script type="application/json" id="tf-order-data">{{ $site->json(['ref' => $ref, 'words' => ['status' => $c['status'], 'cancelStatus' => $c['cancel']['status'], 'cancelErrors' => $c['cancel']['errors'], 'tooMany' => $c['cancel']['tooMany'], 'review' => $c['review'], 'cancelFailed' => $c['cancel']['failed'], 'payFailed' => $c['payFailed'], 'payExpired' => $c['payExpired'], ...\Illuminate\Support\Arr::only($c, ['payEyebrow', 'payBefore', 'how', 'products', 'shippingWith', 'expiredText', 'failedText', 'heading', 'carrierHeading', 'carrierStage', 'carrierEnd', 'thanks', 'stageLabel', 'stageState', 'itemsCount', 'doneTitle', 'itemsBadge', 'stillPending', 'checking', 'paidCheck', 'copy', 'copied', 'stages']), 'track' => $c['track']], 'pay' => $pay]) }}</script>
	<main class="tf-co tf-od" x-data="orderPage">
		<template x-if="showFlow">
			<div>@include('store.partials.flow-steps', ['steps' => $c['flow'], 'label' => $c['flowLabel'], 'variant' => 'inline'])</div>
		</template>

		<div class="tf-wrap tf-co-wrap">
			<template x-if="loading"><p class="tf-co-state" role="status">{{ $pg['cart']['loading'] }}</p></template>
			<template x-if="notFound">
				<div class="tf-od-status">
					<span class="tf-od-status-icon">{{ $site->lineIcon('alert', 28) }}</span>
					<h1>{{ $c['notFoundTitle'] }}</h1>
					<p>{{ $c['notFoundText'] }}</p>
					<div class="tf-od-actions"><a href="{{ $site->route('track') }}" class="tf-btn">{{ $c['lookup'] }}</a></div>
				</div>
			</template>
			<template x-if="failed">
				<div class="tf-od-status" role="alert">
					<span class="tf-od-status-icon">{{ $site->lineIcon('alert', 28) }}</span>
					<h1>{{ $c['loadFailed'] }}</h1>
					<div class="tf-od-actions"><button type="button" class="tf-btn" @click="load">{{ $c['retry'] }}</button></div>
				</div>
			</template>

			{{-- 11 Pembayaran · Menunggu --}}
			<template x-if="view === 'pay'">
				<div class="tf-od-pay tf-od-enter">
					<div class="tf-od-intro">
						<p class="tf-od-eyebrow" x-text="payEyebrow"></p>
						<h1 class="tf-od-title">{{ $c['payTitle'] }}</h1>
						<p class="tf-od-lead">{{ $c['payLead'] }}</p>
					</div>
					<div class="tf-od-cols">
						<div class="tf-od-left">
							<div class="tf-od-card tf-od-deadline">
								<span class="tf-od-deadline-icon">{{ $site->lineIcon('clock', 20) }}</span>
								<div>
									<p class="tf-od-strong" x-text="payBeforeText"></p>
									<p class="tf-od-muted">{{ $c['holdNote'] }}</p>
								</div>
								<strong class="tf-od-clock" role="timer" aria-label="{{ $c['countdown'] }}" x-text="clock"></strong>
							</div>

							<div class="tf-od-card tf-od-bill">
								<p class="tf-od-eyebrow">{{ $c['payMethod'] }}</p>
								<div class="tf-od-bill-row">
									<p class="tf-od-bill-big">Midtrans</p>
									@if ($pay)
										<template x-if="!embedded"><button type="button" class="tf-btn tf-od-paynow" @click="pay" :disabled="payBusy" :aria-busy="payBusy">{{ $site->lineIcon('lock', 16) }} {{ $c['payNow'] }}</button></template>
									@endif
								</div>
								@unless ($pay)
									<p class="tf-shop-issue">{{ $c['paymentPending'] }}</p>
								@endunless
								<template x-if="payError"><p class="tf-shop-issue" role="alert" x-text="payError"></p></template>
								{{-- Panel pembayaran Midtrans ditanam di halaman (snap.embed), bukan popup melayang. --}}
								<template x-if="embedded"><div class="tf-od-embed" id="snap-container"></div></template>
								<hr>
								<div class="tf-od-bill-row">
									<span class="tf-od-muted is-lg">{{ $c['amount'] }}</span>
									<strong class="tf-od-amount" x-text="rupiah(order.total)"></strong>
								</div>
							</div>

							<div class="tf-od-card">
								<h2 class="tf-od-h2">{{ $c['howTitle'] }}</h2>
								<ol class="tf-od-how">
									<template x-for="(step, i) in howSteps" :key="i">
										<li><span x-text="stepNumber(i)"></span><p x-text="step"></p></li>
									</template>
								</ol>
							</div>

							<div class="tf-od-actions is-start">
								<template x-if="payStarted"><button type="button" class="tf-btn tf-od-wide" @click="checkPaid" :disabled="checking" x-text="checkText"></button></template>
								<template x-if="order.can_cancel">@include('store.partials.order-cancel-button')</template>
							</div>
							<template x-if="stillPending"><p class="tf-od-muted" role="status">{{ $c['stillPending'] }}</p></template>
							@include('store.partials.order-cancel', ['withForm' => false])
							@include('store.partials.order-cancel-modal')
						</div>

						<aside class="tf-od-card tf-od-summary">
							<h2 class="tf-od-h2">{{ $c['summaryTitle'] }}</h2>
							<dl class="tf-od-rows">
								<div><dt x-text="productsText"></dt><dd x-text="rupiah(order.subtotal)"></dd></div>
								<div><dt x-text="shippingWithText"></dt><dd x-text="rupiah(order.shipping_fee)"></dd></div>
								<template x-if="hasDiscount"><div><dt>{{ $c['discount'] }} <small x-text="order.voucher_label"></small></dt><dd x-text="discountText"></dd></div></template>
							</dl>
							<div class="tf-od-sum-total"><span>{{ $c['total'] }}</span><strong x-text="rupiah(order.total)"></strong></div>
							<div class="tf-od-verified">
								{{ $site->lineIcon('shieldCheck', 20) }}
								<div><strong>{{ $c['verifiedTitle'] }}</strong><p>{{ $c['verifiedText'] }}</p></div>
							</div>
							<p class="tf-od-muted">{{ $c['saveLink'] }}</p>
							<button type="button" class="tf-od-link" @click="copyLink" x-text="copyText"></button>
						</aside>
					</div>
				</div>
			</template>

			{{-- 11c Pembayaran · Gagal (jendela Snap melaporkan galat; status tetap dari server) --}}
			<template x-if="view === 'payFailed'">
				<div class="tf-od-status tf-od-enter" role="alert">
					<span class="tf-od-status-icon">{{ $site->lineIcon('alert', 28) }}</span>
					<h1>{{ $c['failedTitle'] }}</h1>
					<p x-text="failedText"></p>
					<div class="tf-od-actions">
						<button type="button" class="tf-btn tf-od-wide" @click="retryPay" :disabled="payBusy">{{ $c['tryAgain'] }}</button>
						<a href="{{ $site->route('contact') }}" class="tf-od-ghost">{{ $c['help'] }}</a>
					</div>
				</div>
			</template>

			{{-- 11b Pembayaran · Waktu habis --}}
			<template x-if="view === 'expired'">
				<div class="tf-od-status tf-od-enter">
					<span class="tf-od-status-icon">{{ $site->lineIcon('alert', 28) }}</span>
					<h1>{{ $c['expiredTitle'] }}</h1>
					<p x-text="expiredText"></p>
					<div class="tf-od-actions">
						<a href="{{ $site->route('products') }}" class="tf-btn tf-od-wide">{{ $c['shopAgain'] }}</a>
						<a href="{{ $site->route('contact') }}" class="tf-od-ghost">{{ $c['help'] }}</a>
					</div>
				</div>
			</template>

			{{-- Dibatalkan atau dana dikembalikan --}}
			<template x-if="view === 'closed'">
				<div class="tf-od-status tf-od-enter">
					<span class="tf-od-status-icon">{{ $site->lineIcon('info', 28) }}</span>
					<h1 x-text="statusTitle"></h1>
					<p x-text="statusNote"></p>
					<template x-if="order.cancellation">
						<p class="tf-od-muted">{{ $c['cancel']['number'] }}: <strong x-text="order.cancellation.request_number"></strong> · <span x-text="cancelStatus"></span></p>
					</template>
					<div class="tf-od-actions">
						<a href="{{ $site->route('products') }}" class="tf-btn tf-od-wide">{{ $c['shop'] }}</a>
						<a href="{{ $site->route('contact') }}" class="tf-od-ghost">{{ $c['help'] }}</a>
					</div>
				</div>
			</template>

			{{-- 05 Status Pesanan --}}
			<template x-if="view === 'progress'">
				<div class="tf-od-progress tf-od-enter">
					<section class="tf-od-card tf-od-hero">
						<div class="tf-od-hero-top">
							<div>
								<span class="tf-od-badge"><i aria-hidden="true"></i><span x-text="statusTitle"></span></span>
								<h1 class="tf-od-title" x-text="headingText"></h1>
								<p class="tf-od-lead"><span x-text="thanksText"></span> <span x-text="statusNote"></span></p>
							</div>
							<div class="tf-od-ref">
								<p><span>{{ $c['orderNo'] }}</span><strong x-text="order.order_number"></strong></p>
								<small x-text="createdText"></small>
							</div>
						</div>
						<ol class="tf-od-stages">
							<template x-for="(stage, i) in stages" :key="i">
								<li :class="stageClass(i)" :aria-current="stageCurrent(i)">
									<span class="tf-od-stage-icon" aria-hidden="true">
										<template x-if="i < stageIndex">{{ $site->lineIcon('check', 18) }}</template>
										<template x-if="i >= stageIndex && i === 0">{{ $site->lineIcon('receipt', 18) }}</template>
										<template x-if="i >= stageIndex && i === 1">{{ $site->lineIcon('chef', 18) }}</template>
										<template x-if="i >= stageIndex && i === 2">{{ $site->lineIcon('truck', 18) }}</template>
										<template x-if="i >= stageIndex && i === 3">{{ $site->lineIcon('pin', 18) }}</template>
									</span>
									<small x-text="stageTag(i)"></small>
									<strong x-text="stage[0]"></strong>
									<span x-text="stage[1]"></span>
								</li>
							</template>
						</ol>
					</section>

					<div class="tf-od-cols is-even">
						<div class="tf-od-left">
							<section class="tf-od-card">
								<h2 class="tf-od-h2 is-ruled">{{ $site->lineIcon('truck', 20) }} {{ $c['shipDetails'] }}</h2>
								<div class="tf-od-ship">
									<div>
										<p class="tf-od-label">{{ $c['recipient'] }}</p>
										<p class="tf-od-strong" x-text="recipientText"></p>
										<address class="tf-od-muted"><template x-for="line in streetLines" :key="line"><span x-text="line"></span></template></address>
									</div>
									<div>
										<p class="tf-od-label">{{ $c['method'] }}</p>
										<p><span class="tf-od-tag" x-text="courierText"></span> <strong class="tf-od-strong" x-text="rupiah(order.shipping_fee)"></strong></p>
									</div>
								</div>
							</section>
							{{-- Lacak Paket (FigCraft 05 "Live Delivery Radar" untuk kurir instan, disesuaikan untuk J&T dan SiCepat: tanpa peta live atau profil driver). --}}
							<section class="tf-od-card tf-od-track" aria-labelledby="od-track-title">
								<header class="tf-od-track-head">
									<h2 class="tf-od-h2" id="od-track-title">{{ $site->lineIcon('truck', 20) }} {{ $c['track']['title'] }}</h2>
									<span class="tf-od-track-chip" :class="routeClass"><i aria-hidden="true"></i><span x-text="trackChip"></span></span>
								</header>
								<div class="tf-od-route" :class="routeClass" :style="routeStyle" role="img" :aria-label="trackMessage">
									<div class="tf-od-route-pin is-from"><span class="tf-od-route-dot" aria-hidden="true"></span><small>{{ $c['track']['origin'] }}</small></div>
									<div class="tf-od-route-line" aria-hidden="true"><i class="tf-od-route-fill"></i><span class="tf-od-route-truck"><span>{{ $site->lineIcon('truck', 16) }}</span></span></div>
									<div class="tf-od-route-pin is-to"><span class="tf-od-route-dot" aria-hidden="true">{{ $site->lineIcon('pin', 14) }}{{ $site->lineIcon('check', 14) }}</span><small x-text="destinationText"></small></div>
								</div>
								<div class="tf-od-track-msg" role="status">
									<span class="tf-od-track-msg-icon">{{ $site->lineIcon('send', 16) }}</span>
									<p x-text="trackMessage"></p>
									<small x-text="trackUpdatedText"></small>
								</div>
								<div class="tf-od-track-courier">
									<span class="tf-co-option-logo">
										<template x-if="courierLogoUrl"><img :src="courierLogoUrl" alt="" width="56" height="32" loading="lazy" decoding="async"></template>
										<template x-if="!courierLogoUrl"><b x-text="courierInitials"></b></template>
									</span>
									<div>
										<p class="tf-od-label">{{ $c['track']['courier'] }}</p>
										<p class="tf-od-strong" x-text="courierText"></p>
									</div>
									<div class="tf-od-waybill">
										<p class="tf-od-label">{{ $c['track']['waybill'] }}</p>
										<template x-if="waybill">
											<div class="tf-od-waybill-row"><strong x-text="waybill"></strong><button type="button" class="tf-od-mini" @click="copyWaybill">{{ $site->lineIcon('copy', 14) }} {{ $c['track']['copyWaybill'] }}</button></div>
										</template>
										<template x-if="!waybill"><p class="tf-od-muted">{{ $c['track']['waybillPending'] }}</p></template>
									</div>
								</div>
								{{-- Riwayat per hub/kota dari kurir (Biteship) bila tersedia; kalau tidak, empat tahap dari data pesanan kita. --}}
								<template x-if="hubLoading"><p class="tf-od-muted" role="status">{{ $c['track']['hubLoading'] }}</p></template>
								<template x-if="hasHub">
									<ol class="tf-od-events tf-od-hub" aria-label="{{ $c['track']['hubTitle'] }}">
										<template x-for="(event, i) in hubEvents" :key="event.at + i">
											<li :class="event.latest ? 'is-done is-now' : ''" :aria-current="event.latest ? 'step' : null"><span class="tf-od-event-dot" aria-hidden="true"></span><strong x-text="event.note"></strong><small x-text="event.time"></small></li>
										</template>
									</ol>
								</template>
								<template x-if="!hasHub">
									<ol class="tf-od-events">
										<template x-for="event in trackEvents" :key="event.key">
											<li :class="eventClass(event)" :aria-current="event.current ? 'step' : null"><span class="tf-od-event-dot" aria-hidden="true"></span><strong x-text="event.label"></strong><small x-text="event.time"></small></li>
										</template>
									</ol>
								</template>
								<template x-if="hubFailed"><p class="tf-od-muted">{{ $c['track']['hubUnavailable'] }}</p></template>
								<div class="tf-od-track-share">
									<p>{{ $c['track']['share'] }}</p>
									<div class="tf-od-track-share-actions">
										<button type="button" class="tf-od-mini" @click="copyLink">{{ $site->lineIcon('copy', 14) }} {{ $c['track']['copyLink'] }}</button>
										<a class="tf-od-mini is-wa" :href="shareUrl" target="_blank" rel="noopener noreferrer">{{ $site->lineIcon('send', 14) }} {{ $c['track']['shareWa'] }}</a>
									</div>
								</div>
							</section>
							{{-- Pembatalan: tombol yang membuka modal, sama dengan halaman pembayaran. --}}
							<template x-if="order.can_cancel"><div class="tf-od-actions is-start">@include('store.partials.order-cancel-button')</div></template>
							@include('store.partials.order-cancel', ['withForm' => false])
							@include('store.partials.order-cancel-modal')
						</div>

						<aside class="tf-od-card tf-od-summary">
							<h2 class="tf-od-h2 is-ruled">{{ $site->lineIcon('receipt', 20) }} <span x-text="itemsCountText"></span> <span class="tf-od-paid">{{ $c['paidBadge'] }}</span></h2>
							@include('store.partials.order-items')
							@include('store.partials.order-totals', ['totalLabel' => $c['totalPaid']])
							<div class="tf-od-stack">
								<a href="{{ $site->route('contact') }}" class="tf-od-ghost is-block">{{ $site->lineIcon('headset', 18) }} {{ $c['contactUs'] }}</a>
								<button type="button" class="tf-od-link" @click="copyLink" x-text="copyText"></button>
								<a href="/" class="tf-od-link">{{ $site->lineIcon('arrowLeft', 14) }} {{ $c['home'] }}</a>
							</div>
						</aside>
					</div>
				</div>
			</template>

			{{-- 06 Pesanan Selesai --}}
			<template x-if="view === 'done'">
				<div class="tf-od-done tf-od-enter">
					<section class="tf-od-card tf-od-thanks">
						<span class="tf-od-badge is-gold">{{ $site->lineIcon('check', 14) }} {{ $c['doneBadge'] }}</span>
						<h1 class="tf-od-title" x-text="doneTitleText"></h1>
						<p class="tf-od-lead">{{ $c['doneText'] }}</p>
						<div class="tf-od-chips">
							<span>{{ $c['refLabel'] }} <strong x-text="order.order_number"></strong></span>
							<span>{{ $site->lineIcon('truck', 14) }} {{ $c['courierLabel'] }} <strong x-text="courierText"></strong></span>
						</div>
					</section>

					<template x-if="reviewItems.length">
						<section class="tf-od-card tf-od-rate" aria-labelledby="od-rate-title">
							<h2 class="tf-od-h2 is-ruled" id="od-rate-title">{{ $c['review']['title'] }}</h2>
							<p class="tf-od-muted">{{ $c['review']['hint'] }}</p>
							<ul class="tf-od-rate-list">
								<template x-for="item in reviewItems" :key="item.sku">
									<li>
										<strong x-text="productName(item)"></strong>
										<template x-if="item.review">
											<p class="tf-od-muted"><span class="tf-od-rate-stars"><template x-for="n in 5" :key="n"><i :class="{ 'is-on': n <= item.review.rating }">{{ $site->icon('star', 18) }}</i></template></span> <span>{{ $c['review']['done'] }}</span></p>
										</template>
										<template x-if="!item.review">
											<div>
												<div class="tf-od-rate-stars is-input" role="group" aria-label="{{ $c['review']['title'] }}">
													<template x-for="n in 5" :key="n"><button type="button" :class="{ 'is-on': starOn(item.sku, n) }" :aria-label="starLabel(n)" :aria-pressed="starOn(item.sku, n)" @click="setRate(item.sku, n)">{{ $site->icon('star', 28) }}</button></template>
												</div>
												<textarea rows="3" maxlength="1000" placeholder="{{ $c['review']['placeholder'] }}" aria-label="{{ $c['review']['placeholder'] }}" :value="bodyOf(item.sku)" @input="setBody(item.sku, $event)"></textarea>
												<button type="button" class="tf-btn" :disabled="reviewBusy === item.sku" @click="sendReview(item.sku)">{{ $c['review']['send'] }}</button>
											</div>
										</template>
									</li>
								</template>
							</ul>
							<template x-if="reviewError"><p class="tf-shop-issue" role="alert" x-text="reviewError"></p></template>
						</section>
					</template>

					<div class="tf-od-cols is-even">
						<section class="tf-od-card">
							<h2 class="tf-od-h2 is-ruled">{{ $c['productsTitle'] }} <span class="tf-od-pill" x-text="itemsBadgeText"></span></h2>
							@include('store.partials.order-items')
							<div class="tf-od-address">
								{{ $site->lineIcon('pin', 18) }}
								<div>
									<p class="tf-od-strong">{{ $c['shipAddress'] }}</p>
									<p x-text="recipientText"></p>
									<address class="tf-od-muted"><template x-for="line in streetLines" :key="line"><span x-text="line"></span></template></address>
								</div>
							</div>
						</section>

						<aside class="tf-od-card tf-od-summary">
							<h2 class="tf-od-h2 is-ruled">{{ $c['invoiceTitle'] }} <span class="tf-od-paid">{{ $c['paidBadge'] }}</span></h2>
							@include('store.partials.order-totals', ['totalLabel' => $c['totalPaid']])
							<div class="tf-od-stack">
								<a href="{{ $site->route('products') }}" class="tf-btn tf-btn-block">{{ $c['shop'] }}</a>
								<a href="{{ $site->route('contact') }}" class="tf-od-ghost is-block">{{ $site->lineIcon('headset', 18) }} {{ $c['contactUs'] }}</a>
								<a href="{{ $site->route('products') }}" class="tf-od-link">{{ $c['browse'] }}</a>
							</div>
						</aside>
					</div>
				</div>
			</template>
		</div>
	</main>
@endsection
