{{-- Kepala ringkas untuk subhalaman akun: tautan kembali, ikon, dan judul. --}}
<header class="tf-acct-hero is-compact">
	<span class="tf-acct-hero-ico">@include('store.partials.account-icon', ['name' => $icon])</span>
	<div class="tf-acct-hero-text">
		<a href="{{ $site->route('profile') }}" class="tf-acct-back">@include('store.partials.account-icon', ['name' => 'back']){{ $back }}</a>
		<h1 class="tf-acct-title">{{ $title }}</h1>
	</div>
</header>
