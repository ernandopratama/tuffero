{{-- Bar atas toko (sama dengan .tf-topbar di header toko) untuk halaman galat mandiri. Bahasa lewat ?lang=, seperti SetStoreContext. --}}
<div class="err-topbar">
	<div class="err-topbar-bar">
		<p class="err-promo"><span class="err-promo-dot"></span>{{ trans('store.landing.top.promo', [], $loc) }}</p>
		<div class="err-toplinks">
			<a href="/cek-pesanan">{{ trans('store.landing.top.track', [], $loc) }}</a>
			<a href="/kontak">{{ trans('store.landing.top.help', [], $loc) }}</a>
			<span class="err-toplinks-sep" aria-hidden="true">|</span>
			@foreach (['id', 'en'] as $code)
				<a href="?lang={{ $code }}" lang="{{ $code }}" @class(['is-active' => $loc === $code]) @if ($loc === $code) aria-current="true" @endif>{{ strtoupper($code) }}</a>
			@endforeach
		</div>
	</div>
</div>
