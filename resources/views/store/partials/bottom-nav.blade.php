@php
	// Navbar bawah, hanya tampil di smartphone (di bawah 768px, lewat CSS). Tablet dan desktop tetap memakai header.
	$bnPath = '/'.ltrim(request()->path(), '/');
	$bnAccount = $storeRole ? '/admin' : ($storeUser ? $site->route('profile') : $site->route('account'));
	$bnTabs = [
		['/', 'home', $t['nav'][0], $bnPath === '/'],
		[$site->route('products'), 'basket', $t['nav'][3], str_starts_with($bnPath, $site->route('products'))],
		[$site->route('cart'), 'bag', $t['aria']['cart'], $bnPath === $site->route('cart')],
		[$bnAccount, 'user', $t['aria']['account'], $bnPath === $site->route('account') || str_starts_with($bnPath, $site->route('profile'))],
	];
	$bnLinks = [
		[$site->route('promos'), 'gift', $t['nav'][4]],
		[$site->route('vouchers'), 'ticket', $t['bnav']['vouchers']],
		[$site->route('track'), 'truck', $t['top']['track']],
		[$site->route('contact'), 'phone', $t['nav'][7]],
		[$site->route('about'), 'info', $t['nav'][1]],
		[$site->route('services'), 'chef', $t['nav'][2]],
		[$site->route('gallery'), 'image', $t['nav'][6]],
	];
@endphp
<div x-data="bottomNav" style="display: contents">
	<div class="tf-bnav-scrim" :class="open ? 'is-on' : ''" aria-hidden="true" @click="close"></div>
	<div class="tf-bnav" :class="open ? 'is-open' : ''">
		<div class="tf-bnav-box">
			<div x-ref="panel" id="tf-bnav-panel" class="tf-bnav-panel" role="dialog" aria-modal="true" aria-label="{{ $t['bnav']['panel'] }}" tabindex="-1" inert :inert="!open">
				<form class="tf-bnav-search" role="search" action="{{ $site->route('products') }}">
					{{ $site->lineIcon('search', 18) }}
					<input type="search" name="q" autocomplete="off" enterkeyhint="search" placeholder="{{ $t['search']['placeholder'] }}" aria-label="{{ $t['aria']['search'] }}">
					<button type="submit" aria-label="{{ $t['search']['submit'] }}">{{ $site->lineIcon('arrowRight', 18) }}</button>
				</form>
				<ul class="tf-bnav-grid">
					@foreach ($bnLinks as [$href, $icon, $label])
						<li><a href="{{ $href }}" class="tf-bnav-tile"@if ($bnPath === $href) aria-current="page"@endif><span>{{ $site->lineIcon($icon, 22) }}</span>{{ $label }}</a></li>
					@endforeach
					<li><button type="button" class="tf-bnav-tile" @click="openChat"><span>{{ $site->lineIcon('chat', 22) }}</span>{{ $t['bnav']['chat'] }}</button></li>
				</ul>
				<div class="tf-bnav-foot">
					<div class="tf-bnav-lang" role="group" aria-label="{{ $t['aria']['lang'] }}">
						@foreach ($locales as $code)
							<button type="button" data-set-locale="{{ $code }}" lang="{{ $code }}" aria-pressed="{{ $locale === $code ? 'true' : 'false' }}">{{ strtoupper($code) }}</button>
						@endforeach
					</div>
					@if ($storeUser)
						<button type="button" class="tf-bnav-out" @click="signOut">{{ $site->lineIcon('logout', 18) }}{{ $t['account']['signOut'] }}</button>
					@endif
				</div>
				<p x-show="signOutFailed" class="tf-account-error" role="alert" style="display: none">{{ $t['account']['signOutFailed'] }}</p>
			</div>
			<nav class="tf-bnav-tabs" aria-label="{{ $t['bnav']['label'] }}" :inert="open">
				@foreach ($bnTabs as [$href, $icon, $label, $active])
					<a href="{{ $href }}" class="tf-bnav-tab"@if ($active) aria-current="page"@endif>
						<span class="tf-bnav-ico">
							{{ $site->lineIcon($icon, 22) }}
							@if ($icon === 'bag')
								<span class="tf-bnav-count" x-show="$store.cart.count" x-text="$store.cart.count" @if (! $cartCount) style="display: none" @endif>{{ $cartCount ?: '' }}</span>
							@endif
						</span>
						<span class="tf-bnav-label">{{ $label }}</span>
					</a>
				@endforeach
			</nav>
		</div>
		<button x-ref="fab" type="button" class="tf-bnav-fab" aria-controls="tf-bnav-panel" aria-expanded="false" :aria-expanded="open" aria-label="{{ $t['menu']['open'] }}" :aria-label="open ? @js($t['menu']['close']) : @js($t['menu']['open'])" @click="toggle">
			{{ $site->lineIcon('plus', 24) }}
		</button>
	</div>
</div>
