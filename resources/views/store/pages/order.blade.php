@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-order-data">{{ $site->json(['ref' => $ref, 'words' => ['status' => $c['status'], 'cancelStatus' => $c['cancel']['status'], 'cancelErrors' => $c['cancel']['errors'], 'tooMany' => $c['cancel']['tooMany'], 'cancelFailed' => $c['cancel']['failed']]]) }}</script>
	<main class="tf-wrap tf-shop" x-data="orderPage">
		<template x-if="loading"><p class="tf-shop-note" role="status">{{ $pg['cart']['loading'] }}</p></template>
		<template x-if="notFound">
			<div class="tf-cat-empty">
				<h1 class="tf-shop-title">{{ $c['notFoundTitle'] }}</h1>
				<p>{{ $c['notFoundText'] }}</p>
				<a href="{{ $site->route('track') }}" class="tf-btn">{{ $c['lookup'] }}</a>
			</div>
		</template>
		<template x-if="failed">
			<div class="tf-cat-empty" role="alert">
				<p>{{ $c['loadFailed'] }}</p>
				<button type="button" class="tf-btn" @click="load">{{ $c['retry'] }}</button>
			</div>
		</template>

		<template x-if="shown">
			<div style="display: contents">
				<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
				<p class="tf-shop-lead">{{ $c['number'] }}: <strong x-text="order.order_number"></strong></p>

				<template x-if="stepIndex >= 0">
					<ol class="tf-order-steps">
						@foreach ($c['steps'] as $i => $step)
							<li :class="stepClass({{ $i }})" :aria-current="stepCurrent({{ $i }})"><span>{{ $i + 1 }}</span>{{ $step }}</li>
						@endforeach
					</ol>
				</template>

				<div class="tf-shop-grid">
					<div class="tf-shop-stack">
						<section class="tf-shop-card">
							<h2 x-text="statusTitle"></h2>
							<p class="tf-shop-note" x-text="statusNote"></p>
							<template x-if="isPending">
								<div style="display: contents">
									<p class="tf-order-clock">{{ $c['countdown'] }}: <strong x-text="clock"></strong></p>
									<p class="tf-shop-issue">{{ $c['paymentPending'] }}</p>
								</div>
							</template>
						</section>

						<section class="tf-shop-card">
							<h2>{{ $c['shipTo'] }}</h2>
							<address class="tf-order-address"><template x-for="line in addressLines" :key="line"><span x-text="line"></span></template></address>
							<p class="tf-shop-note">{{ $c['courier'] }}: <span x-text="courierText"></span></p>
						</section>

						<template x-if="showCancel">
							<section class="tf-shop-card">
								<h2>{{ $c['cancel']['title'] }}</h2>
								<template x-if="order.cancellation">
									<div style="display: contents">
										<p class="tf-shop-note">{{ $c['cancel']['number'] }}: <strong x-text="order.cancellation.request_number"></strong></p>
										<p class="tf-shop-note" role="status" x-text="cancelStatus"></p>
										<template x-if="order.cancellation.rejection_reason"><p class="tf-shop-note" x-text="order.cancellation.rejection_reason"></p></template>
										<template x-if="cancelDone && cancelDone.contact_url"><a :href="cancelDone.contact_url" target="_blank" rel="noopener noreferrer" class="tf-btn tf-order-contact">{{ $c['cancel']['contact'] }}</a></template>
									</div>
								</template>
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
							</section>
						</template>

						<section class="tf-shop-card">
							<p class="tf-shop-note">{{ $c['saveLink'] }}</p>
							<button type="button" class="tf-shop-link" @click="copyLink" x-text="copied ? @js($c['copied']) : @js($c['copy'])"></button>
						</section>
					</div>

					<aside class="tf-shop-card tf-shop-summary">
						<h2>{{ $c['items'] }}</h2>
						<ul class="tf-shop-lines">
							<template x-for="item in order.items" :key="item.sku"><li><span x-text="lineLabel(item)"></span><span x-text="rupiah(item.line_total)"></span></li></template>
						</ul>
						<dl>
							<div><dt>{{ $c['subtotal'] }}</dt><dd x-text="rupiah(order.subtotal)"></dd></div>
							<div><dt>{{ $c['shipping'] }}</dt><dd x-text="rupiah(order.shipping_fee)"></dd></div>
							<div class="is-total"><dt>{{ $c['total'] }}</dt><dd x-text="rupiah(order.total)"></dd></div>
						</dl>
						<a href="{{ $site->route('products') }}" class="tf-auth-link">{{ $c['shop'] }}</a>
					</aside>
				</div>
			</div>
		</template>
	</main>
@endsection
