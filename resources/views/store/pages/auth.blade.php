@extends('store.layouts.app', ['footer' => false, 'mainbar' => false])

@section('content')
	<script type="application/json" id="tf-auth-data">{{ $site->json(['words' => $a, 'mode' => $mode, 'dev' => app()->isLocal(), 'demoCode' => app()->isLocal() ? '123456' : null]) }}</script>
	<main class="tf-wrap tf-auth" x-data="authPage">
		<aside class="tf-auth-photo">
			<img src="/images/Produk/gallery/Nastar Sultan 1.webp" alt="" width="800" height="800" fetchpriority="high">
			<div>
				<p class="tf-eyebrow tf-eyebrow-gold">{{ $a['panel']['eyebrow'] }}</p>
				<p class="tf-auth-photo-title">{{ $a['panel']['title'] }}</p>
				<ul>
					@foreach ($a['panel']['items'] as $item)
						<li>{{ $site->icon('check', 14) }}{{ $item }}</li>
					@endforeach
				</ul>
			</div>
		</aside>

		<!-- x-if: tiap langkah memudar masuk; fokus dipindah oleh enterStep -->
		<template x-if="showForm">
			<div class="tf-auth-panel">
				<div class="tf-auth-top">@include('store.partials.home-button', ['label' => $a['backHome']])</div>
				<p class="tf-eyebrow">{{ $a['eyebrow'] }}</p>
				<h1 x-text="words.title"></h1>
				<p class="tf-auth-lead" x-text="words.lead"></p>

				<div class="tf-auth-tabs" role="group" aria-label="{{ $a['modeLabel'] }}">
					<button type="button" :aria-pressed="!isRegister" @click="tabLogin">{{ $a['login']['tab'] }}</button>
					<button type="button" :aria-pressed="isRegister" @click="tabRegister">{{ $a['register']['tab'] }}</button>
				</div>

				<form novalidate @submit.prevent="submit">
					<template x-if="isRegister">
						<div style="display: contents">
							<div class="tf-auth-field" :class="fieldError ? 'is-error' : ''">
								<label for="tf-auth-email">{{ $a['identity']['emailLabel'] }}</label>
								<div class="tf-auth-input">
									{{ $site->lineIcon('mail') }}
									<input id="tf-auth-email" x-model="email" type="email" name="email" autocomplete="email" inputmode="email" spellcheck="false" autocapitalize="off" placeholder="{{ $a['identity']['emailPlaceholder'] }}" :aria-invalid="!!fieldError" aria-describedby="tf-auth-email-hint" @input="clearField">
								</div>
								<p id="tf-auth-email-hint" :role="fieldError ? 'alert' : null" x-text="fieldError"></p>
							</div>
							<div class="tf-auth-field" :class="phoneError ? 'is-error' : ''">
								<label for="tf-auth-phone">{{ $a['identity']['phoneLabel'] }}</label>
								<div class="tf-auth-input">
									<span aria-hidden="true">+62</span>
									<input id="tf-auth-phone" x-model="phone" type="tel" name="tel" autocomplete="tel-national" inputmode="tel" placeholder="{{ $a['identity']['phonePlaceholder'] }}" :aria-invalid="!!phoneError" aria-describedby="tf-auth-phone-hint" @input="clearPhone">
								</div>
								<p id="tf-auth-phone-hint" :role="phoneError ? 'alert' : null" x-text="phoneHint"></p>
							</div>
						</div>
					</template>
					<template x-if="!isRegister">
						<div class="tf-auth-field" :class="fieldError ? 'is-error' : ''">
							<label for="tf-auth-id">{{ $a['identity']['label'] }}</label>
							<div class="tf-auth-input">
								{{ $site->lineIcon('mail') }}
								<input id="tf-auth-id" x-model="identity" type="text" name="username" autocomplete="username" spellcheck="false" autocapitalize="off" placeholder="{{ $a['identity']['placeholder'] }}" :aria-invalid="!!fieldError" aria-describedby="tf-auth-hint" @input="clearField">
							</div>
							<p id="tf-auth-hint" :role="fieldError ? 'alert' : null" x-text="fieldError"></p>
						</div>
					</template>
					<div class="tf-auth-field" :class="passwordError ? 'is-error' : ''">
						<label for="tf-auth-password">{{ $a['password']['label'] }}</label>
						<div class="tf-auth-input">
							{{ $site->lineIcon('lock') }}
							<input id="tf-auth-password" x-model="password" :type="passwordType" name="password" :autocomplete="passwordAutocomplete" :aria-invalid="!!passwordError" aria-describedby="tf-auth-password-hint" @input="clearPassword">
							<button type="button" class="tf-auth-show" :aria-label="passwordLabel" @click="togglePassword">
								<span x-show="!showPassword" style="display: contents">{{ $site->lineIcon('eye', 20) }}</span>
								<span x-show="showPassword" style="display: none">{{ $site->lineIcon('eyeOff', 20) }}</span>
							</button>
						</div>
						<p id="tf-auth-password-hint" :role="passwordError ? 'alert' : null" x-text="passwordHint"></p>
					</div>
					<button type="submit" class="tf-btn tf-btn-block" :disabled="busy" x-text="words.submit"></button>
				</form>
				<template x-if="notice"><p class="tf-auth-notice" role="alert" x-text="notice"></p></template>

				<div class="tf-auth-or"><span>{{ $a['or'] }}</span></div>
				<button type="button" class="tf-auth-google" @click="googleNotice">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"></path><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"></path><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"></path><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"></path></svg>
					{{ $a['google'] }}
				</button>
				<a href="{{ $site->route('products') }}" class="tf-auth-link">{{ $a['guest'] }}</a>
				<p class="tf-auth-terms">{{ $a['termsA'] }} <a href="{{ $site->route('contact') }}#syarat">{{ $a['terms'] }}</a> {{ $a['termsB'] }} <a href="{{ $site->route('contact') }}#privasi">{{ $a['privacy'] }}</a>{{ $a['termsC'] && $a['termsC'] !== '.' ? ' ' : '' }}{{ $a['termsC'] }}</p>
			</div>
		</template>

		<template x-if="showDone">
			<div class="tf-auth-panel">
				<p class="tf-eyebrow">{{ $a['otp']['eyebrow'] }}</p>
				<h1>{{ $a['otp']['doneTitle'] }}</h1>
				<p class="tf-auth-lead" role="status">{{ $a['otp']['doneText'] }}</p>
				<a href="/" class="tf-btn tf-btn-block">{{ $a['otp']['home'] }}</a>
			</div>
		</template>

		<template x-if="showOtp">
			<div class="tf-auth-panel">
				<div class="tf-auth-top is-split">
					<button type="button" class="tf-auth-back" @click="backToRegister">
						<span aria-hidden="true">←</span> <span x-text="channelWords.change"></span>
					</button>
					@include('store.partials.home-button', ['label' => $a['backHome']])
				</div>
				<p class="tf-eyebrow" x-text="otpEyebrow"></p>
				<h1 x-text="otpTitle"></h1>
				<p class="tf-auth-lead" x-text="otpLead"></p>
				<template x-if="showDemo"><p class="tf-auth-demo" x-text="demoText"></p></template>

				<form novalidate @submit.prevent="verify">
					<label for="tf-otp-input" class="visually-hidden">{{ $a['otp']['inputLabel'] }}</label>
					<!-- Satu input asli di atas enam kotak: isi otomatis SMS dan tempel enam digit langsung bekerja -->
					<div class="tf-otp" :class="otpClass">
						<input id="tf-otp-input" x-ref="codeEl" :value="code" type="text" name="one-time-code" inputmode="numeric" autocomplete="one-time-code" enterkeyhint="done" :disabled="locked" :aria-invalid="!!codeError" aria-describedby="tf-otp-status" @input="onCodeInput">
						<template x-for="i in codeCells" :key="i"><span :class="cellClass(i)" aria-hidden="true" x-text="cellText(i)"></span></template>
					</div>
					<div id="tf-otp-status" class="tf-otp-status">
						<template x-if="locked"><p role="alert" class="is-strong">{{ $site->lineIcon('lock', 15) }}<span x-text="lockedText"></span></p></template>
						<template x-if="showCodeError"><p role="alert" class="is-error" x-text="codeError"></p></template>
						<template x-if="showStatus"><p role="status" x-text="codeStatus"></p></template>
						<template x-if="showWait"><p>{{ $site->lineIcon('clock', 15) }}<span x-text="resendText"></span></p></template>
						<template x-if="showResend"><button type="button" class="tf-auth-resend" @click="resend">{{ $a['otp']['resend'] }}</button></template>
					</div>
					<button type="submit" class="tf-btn tf-btn-block" :disabled="busy || locked" x-text="submitOtpText"></button>
				</form>
				<template x-if="locked"><a href="{{ $site->route('contact') }}" class="tf-auth-link">{{ $a['otp']['help'] }}</a></template>
				<template x-if="!locked"><p class="tf-auth-terms" x-text="channelWords.noCode"></p></template>
			</div>
		</template>
	</main>
@endsection
