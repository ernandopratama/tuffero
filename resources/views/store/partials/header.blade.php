@php
	// "Pilihan" menuju bagian di beranda, jadi menunya hanya tampil di beranda.
	$navItems = collect($site::NAV_LINKS)
		->map(fn ($href, $i) => ['href' => $href, 'label' => $t['nav'][$i]])
		->filter(fn ($item) => $item['href'] !== $site->route('offers') || request()->path() === '/');
	$current = '/'.ltrim(request()->path(), '/');
@endphp
<header class="tf-header" x-data="siteHeader">
	<div class="tf-topbar">
		<div class="tf-bar">
			<p class="tf-promo"><span class="tf-promo-dot"></span>{{ $t['top']['promo'] }}</p>
			<div class="tf-toplinks">
				<a href="{{ $site->route('track') }}">{{ $t['top']['track'] }}</a>
				<a href="{{ $site->route('contact') }}">{{ $t['top']['help'] }}</a>
				<span class="tf-toplinks-sep" aria-hidden="true">|</span>
				@foreach ($locales as $code)
					<a href="?lang={{ $code }}" data-set-locale="{{ $code }}" @class(['is-active' => $locale === $code]) @if ($locale === $code) aria-current="true" @endif lang="{{ $code }}" aria-label="{{ $t['aria']['lang'] }}: {{ strtoupper($code) }}">{{ strtoupper($code) }}</a>
				@endforeach
			</div>
		</div>
	</div>
	@if ($mainbar)
	<div x-ref="mainBar" class="tf-mainbar">
		<div class="tf-bar">
			<a class="tf-logo" href="/"><img src="/images/Logo/Tuffero Script Logo White - NoBckgrnd.webp" alt="Tuffero" width="135" height="45"></a>
			<nav class="tf-nav" aria-label="{{ $t['aria']['main'] }}">
				<ul id="menu">
					@foreach ($navItems as $item)
						<li class="nav-item"><a @class(['nav-link', 'is-active' => $item['href'] === $current]) @if ($item['href'] === $current) aria-current="page" aria-disabled="true" @else href="{{ $item['href'] }}" @endif>{{ $item['label'] }}</a></li>
					@endforeach
				</ul>
			</nav>
			<div class="tf-actions">
				<button type="button" class="tf-action" :class="searchOpen ? 'is-on' : ''" aria-label="{{ $t['aria']['search'] }}" aria-controls="tf-search" aria-expanded="false" :aria-expanded="searchOpen" @click="toggleSearch">{{ $site->icon('search', 20) }}</button>
				@if ($storeUser)
					<div x-ref="accountBox" class="tf-account">
						<button x-ref="accountToggle" type="button" class="tf-action" :class="accountOpen ? 'is-on' : ''" aria-label="{{ $t['aria']['account'] }}" aria-controls="tf-account-menu" aria-expanded="false" :aria-expanded="accountOpen" @click="toggleAccount">{{ $site->icon('person', 20) }}</button>
						<div x-show="accountOpen" id="tf-account-menu" class="tf-account-menu" style="display: none">
							@php($initials = collect(preg_split('/\s+/', trim($storeUser->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''))
							<div class="tf-account-head">
								<p>{{ $t['account']['signedInAs'] }}</p>
								<div class="tf-account-who">
									<span class="tf-account-avatar" aria-hidden="true">{{ $initials }}</span>
									<span class="tf-account-id"><strong>{{ $storeUser->name }}</strong><span>{{ $storeUser->email }}</span></span>
								</div>
							</div>
							@php($menuLinks = $storeRole
								? [['/admin', 'admin', $t['account']['admin']]]
								: [[$site->route('profile'), 'profile', $t['account']['mine']], [$site->route('orders'), 'order', $t['account']['orders']], [$site->route('addresses'), 'address', $t['account']['addresses']], [$site->route('myVouchers'), 'voucher', $t['account']['vouchers']]])
							<div class="tf-account-group">
								@foreach ($menuLinks as [$href, $icon, $label])
									<a href="{{ $href }}" class="tf-account-item"><span class="tf-account-ico">@include('store.partials.account-icon', ['name' => $icon])</span><span>{{ $label }}</span>@include('store.partials.account-icon', ['name' => 'chevron'])</a>
								@endforeach
							</div>
							<div class="tf-account-group">
								<a href="{{ $site->route('track') }}" class="tf-account-item"><span class="tf-account-ico">@include('store.partials.account-icon', ['name' => 'track'])</span><span>{{ $t['top']['track'] }}</span>@include('store.partials.account-icon', ['name' => 'chevron'])</a>
								<a href="{{ $site->route('contact') }}" class="tf-account-item"><span class="tf-account-ico">@include('store.partials.account-icon', ['name' => 'help'])</span><span>{{ $t['top']['help'] }}</span>@include('store.partials.account-icon', ['name' => 'chevron'])</a>
							</div>
							<div class="tf-account-group">
								<button type="button" class="tf-account-item is-danger" @click="signOut"><span class="tf-account-ico">@include('store.partials.account-icon', ['name' => 'logout'])</span><span>{{ $t['account']['signOut'] }}</span>@include('store.partials.account-icon', ['name' => 'chevron'])</button>
								<p x-show="signOutFailed" class="tf-account-error" role="alert" style="display: none">{{ $t['account']['signOutFailed'] }}</p>
							</div>
						</div>
					</div>
				@else
					<a href="{{ $site->route('account') }}" class="tf-action" aria-label="{{ $t['aria']['account'] }}">{{ $site->icon('person', 20) }}</a>
				@endif
				<a href="{{ $site->route('cart') }}" class="tf-action" aria-label="{{ $t['aria']['cart'] }}">{{ $site->icon('shopping_bag', 20) }}<span class="tf-count" x-show="$store.cart.count" x-text="$store.cart.count" @if (! $cartCount) style="display: none" @endif>{{ $cartCount ?: '' }}</span></a>
				<a href="{{ $site->route('products') }}" class="tf-btn tf-btn-shop tf-fly-btn">
					<span class="tf-fly"><span class="tf-fly-in"><span class="tf-icon tf-icon-cart" aria-hidden="true"></span></span></span>
					<span class="tf-btn-label">{{ $t['shop'] }}</span>
				</a>
			</div>
			<div class="tf-mobile-actions">
				<a href="{{ $site->route('cart') }}" class="tf-action" aria-label="{{ $t['aria']['cart'] }}">{{ $site->icon('shopping_bag', 22) }}<span class="tf-count" x-show="$store.cart.count" x-text="$store.cart.count" @if (! $cartCount) style="display: none" @endif>{{ $cartCount ?: '' }}</span></a>
				<button x-ref="menuToggle" type="button" class="tf-burger" :class="menuOpen ? 'is-open' : ''" aria-label="{{ $t['menu']['open'] }}" :aria-label="menuOpen ? @js($t['menu']['close']) : @js($t['menu']['open'])" aria-expanded="false" :aria-expanded="menuOpen" aria-controls="tf-mobile-menu" @click="toggleMenu">
					<svg viewBox="0 0 32 32" aria-hidden="true">
						<path class="tf-burger-line tf-burger-ends" d="M27 10 13 10C10.8 10 9 8.2 9 6 9 3.5 10.8 2 13 2 15.2 2 17 3.8 17 6L17 26C17 28.2 18.8 30 21 30 23.2 30 25 28.2 25 26 25 23.8 23.2 22 21 22L7 22"></path>
						<path class="tf-burger-line" d="M7 16 27 16"></path>
					</svg>
				</button>
			</div>
		</div>
		<div id="tf-mobile-menu" class="tf-mobile" :class="menuOpen ? 'is-open' : ''" inert :inert="!menuOpen">
			<form class="tf-search-form" role="search" action="{{ $site->route('products') }}" @submit.prevent="submitSearch">
				<label class="tf-search-field">
					{{ $site->icon('search', 20) }}
					<input x-model="searchQuery" type="search" name="q" autocomplete="off" enterkeyhint="search" placeholder="{{ $t['search']['placeholder'] }}" aria-label="{{ $t['aria']['search'] }}">
				</label>
				<button type="submit" class="tf-search-go">{{ $t['search']['submit'] }}</button>
			</form>
			<nav aria-label="{{ $t['aria']['main'] }}">
				<ul>
					@foreach ($navItems as $item)
						<li><a @class(['is-active' => $item['href'] === $current]) @if ($item['href'] === $current) aria-current="page" aria-disabled="true" @else href="{{ $item['href'] }}" @click="closeMenuLink" @endif>{{ $item['label'] }}{{ $site->icon('north_east', 14) }}</a></li>
					@endforeach
				</ul>
			</nav>
			@if ($storeUser)
				<p class="tf-account-id tf-mobile-id"><strong>{{ $storeUser->name }}</strong><span>{{ $storeUser->email }}</span></p>
				@if ($storeRole)
					<a href="/admin" class="tf-btn tf-btn-block tf-mobile-login">{{ $t['account']['admin'] }}</a>
				@else
					<a href="{{ $site->route('profile') }}" class="tf-btn tf-btn-block tf-mobile-login">{{ $t['account']['mine'] }}</a>
				@endif
				<button type="button" class="tf-btn tf-btn-block tf-mobile-login" @click="signOut">{{ $t['account']['signOut'] }}</button>
				<p x-show="signOutFailed" class="tf-account-error" role="alert" style="display: none">{{ $t['account']['signOutFailed'] }}</p>
			@else
				<a href="{{ $site->route('account') }}" class="tf-btn tf-btn-block tf-mobile-login">{{ $site->icon('person', 20) }}{{ $t['menu']['login'] }}</a>
			@endif
			<div class="tf-mobile-foot">
				@if ($storeUser && ! $storeRole)
					<a href="{{ $site->route('orders') }}">{{ $t['account']['orders'] }}</a>
				@else
					<a href="{{ $site->route('track') }}">{{ $t['top']['track'] }}</a>
				@endif
				<a href="{{ $site->route('contact') }}">{{ $t['top']['help'] }}</a>
				<div class="tf-mobile-lang" role="group" aria-label="{{ $t['aria']['lang'] }}">
					@foreach ($locales as $code)
						<button type="button" data-set-locale="{{ $code }}" lang="{{ $code }}" aria-pressed="{{ $locale === $code ? 'true' : 'false' }}">{{ strtoupper($code) }}</button>
					@endforeach
				</div>
			</div>
		</div>
		<div id="tf-search" x-ref="searchBox" class="tf-search" :class="searchOpen ? 'is-open' : ''" inert :inert="!searchOpen">
			<div class="tf-search-inner">
				<form class="tf-search-form" role="search" action="{{ $site->route('products') }}" @submit.prevent="submitSearch">
					<label class="tf-search-field">
						{{ $site->icon('search', 22) }}
						<input x-ref="searchInput" x-model="searchQuery" type="search" name="q" autocomplete="off" enterkeyhint="search" placeholder="{{ $t['search']['placeholder'] }}" aria-label="{{ $t['aria']['search'] }}">
					</label>
					<button type="submit" class="tf-search-go">{{ $t['search']['submit'] }}</button>
					<button type="button" class="tf-search-close" aria-label="{{ $t['search']['close'] }}" @click="closeSearch">
						<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"></path></svg>
					</button>
				</form>
				<p class="tf-search-hints"><span>{{ $t['search']['popular'] }}</span>@foreach ($t['search']['hints'] as $hint)<a href="{{ $site->route('products') }}?q={{ rawurlencode($hint) }}" @click="closeSearch">{{ $hint }}</a>@endforeach</p>
			</div>
		</div>
	</div>
	@endif
</header>
