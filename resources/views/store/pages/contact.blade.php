@extends('store.layouts.app')

@php
	$hoursLabel = fn ($hours) => $hours ? $site->fill($p['timeFormat'], ['open' => $p['times'][$hours[0]], 'close' => $p['times'][$hours[1]]]) : $p['closed'];
@endphp

@section('content')
	<main x-data="contactPage" data-phone="{{ str_replace('-', '', $site->phoneDisplay()) }}" data-copied="{{ $p['phone']['copied'] }}">
		@include('store.partials.page-hero', ['hero' => $p['hero'], 'current' => $t['nav'][7], 'photo' => $site->productPhoto('Nastar Classic 5')])

		<section class="tf-wrap tf-page-section tf-contact-grid">
			<div class="tf-contact-cards">
				<article class="tf-contact-card wow fadeInUp">
					<span class="tf-round">{{ $site->lineIcon('phone') }}</span>
					<h2>{{ $p['phone']['title'] }}</h2>
					<p>{{ $p['phone']['text'] }}</p>
					<div class="tf-contact-actions">
						<a href="{{ $site->phoneHref() }}" class="tf-contact-value">{{ $site->phoneDisplay() }}</a>
						<button type="button" class="tf-contact-copy" @click="copyPhone"><span role="status" x-text="copied ? @js($p['phone']['copied']) : @js($p['phone']['copy'])">{{ $p['phone']['copy'] }}</span></button>
					</div>
				</article>
				<article class="tf-contact-card wow fadeInUp" data-wow-delay="0.06s">
					<span class="tf-round">{{ $site->lineIcon('pin') }}</span>
					<h2>{{ $p['address']['title'] }}</h2>
					<p>{{ $t['footer']['address'] }}</p>
					<a href="{{ $site::MAPS_URL }}" class="tf-more" target="_blank" rel="noopener noreferrer" aria-label="{{ $p['address']['open'] }} ({{ $t['aria']['newTab'] }})">{{ $p['address']['open'] }} {{ $site->icon('north_east', 12) }}</a>
				</article>
				<article class="tf-contact-card wow fadeInUp" data-wow-delay="0.08s">
					<span class="tf-round">{{ $site->lineIcon('instagram') }}</span>
					<h2>Instagram</h2>
					<p>{{ $p['instagram'] }}</p>
					<a href="{{ $site::INSTAGRAM_URL }}" class="tf-more" target="_blank" rel="noopener noreferrer" aria-label="{{ $site::INSTAGRAM_HANDLE }} (Instagram, {{ $t['aria']['newTab'] }})">{{ $site::INSTAGRAM_HANDLE }} {{ $site->icon('north_east', 12) }}</a>
				</article>
				<article class="tf-contact-card wow fadeInUp" data-wow-delay="0.12s">
					<span class="tf-round">{{ $site->lineIcon('tiktok') }}</span>
					<h2>TikTok</h2>
					<p>{{ $p['tiktok'] }}</p>
					<a href="{{ $site::TIKTOK_URL }}" class="tf-more" target="_blank" rel="noopener noreferrer" aria-label="{{ $site::TIKTOK_HANDLE }} (TikTok, {{ $t['aria']['newTab'] }})">{{ $site::TIKTOK_HANDLE }} {{ $site->icon('north_east', 12) }}</a>
				</article>
			</div>

			<aside class="tf-hours wow fadeInUp" data-wow-delay="0.06s">
				<div class="tf-hours-head">
					<h2>{{ $t['hero']['factsTitle'] }}</h2>
					<span @class(['tf-hours-status', 'is-open' => $status['open']])>{{ $status['open'] ? $p['openNow'] : $p['closedNow'] }}</span>
				</div>
				<ul>
					@foreach ($p['days'] as $i => $day)
						<li @class(['is-today' => $status['day'] === $i]) @if ($status['day'] === $i) aria-current="date" @endif>
							<span>{{ $day }}</span><span>{{ $hoursLabel($site::OPEN_HOURS[$i]) }}</span>
						</li>
					@endforeach
				</ul>
				<p>{{ $p['timezone'] }}</p>
			</aside>
		</section>

		<section class="tf-wrap tf-contact-map wow fadeInUp">
			<template x-if="showMap"><iframe src="{{ $site::MAPS_EMBED_URL }}" title="{{ $p['map']['title'] }}" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></template>
			<div x-show="!showMap">
				<span class="tf-round tf-round-lg">{{ $site->lineIcon('pin', 24) }}</span>
				<h2>{{ $p['map']['title'] }}</h2>
				<p>{{ $p['map']['text'] }}</p>
				<button type="button" class="tf-btn" @click="openMap">{{ $p['map']['show'] }}</button>
			</div>
		</section>

		<section class="tf-wrap tf-page-section">
			<div class="tf-contact-help">
				<a href="{{ $site->route('services') }}#faq" class="wow fadeInUp">
					<span class="tf-round">{{ $site->lineIcon('question') }}</span>
					<span><strong>{{ $p['help']['faq'] }}</strong><em>{{ $p['help']['faqText'] }}</em></span>
					{{ $site->icon('north_east', 14) }}
				</a>
				<a href="{{ $site->route('products') }}" class="wow fadeInUp" data-wow-delay="0.06s">
					<span class="tf-round">{{ $site->icon('shopping_bag', 18) }}</span>
					<span><strong>{{ $p['help']['shop'] }}</strong><em>{{ $p['help']['shopText'] }}</em></span>
					{{ $site->icon('north_east', 14) }}
				</a>
			</div>
		</section>

		{{-- S&K (kiri) dan Kebijakan Privasi (kanan): dua kartu berdampingan di layar lebar, bertumpuk di HP. Id #syarat dan #privasi tetap jadi anchor. --}}
		<section class="tf-page-section tf-wrap tf-legal" aria-label="{{ $p['legal']['eyebrow'] }}">
			@foreach ($p['legal']['docs'] as $doc)
				<article id="{{ $doc['id'] }}" class="tf-legal-card wow fadeInUp" data-wow-delay="{{ $loop->index * 0.1 }}s">
					<header class="tf-legal-head">
						<span class="tf-legal-icon" aria-hidden="true">
							@if ($doc['id'] === 'privasi')
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.4 2.9 8.3 7 10 4.1-1.7 7-5.6 7-10V6z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>
							@else
								<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>
							@endif
						</span>
						<div class="tf-legal-titles">
							<p class="tf-eyebrow">{{ $p['legal']['eyebrow'] }}</p>
							<h2 class="tf-legal-title">{{ $doc['title'] }}</h2>
						</div>
					</header>
					<div class="tf-legal-meta">
						@if (isset($terms[$doc['id']]))
							<p class="tf-legal-version">{{ str_replace(['{version}', '{date}'], [$terms[$doc['id']]['version'], $terms[$doc['id']]['date']], $p['legal']['version']) }}</p>
						@endif
						<p class="tf-legal-count">{{ str_replace('{n}', count($doc['items']), $p['legal']['count']) }}</p>
					</div>
					<div class="tf-legal-list">
						@foreach ($doc['items'] as [$heading, $body])
							<details>
								<summary><span class="tf-legal-num" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span><span class="tf-legal-q">{{ $heading }}</span><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
								<div class="tf-legal-body">
									{{-- Baris kosong memisahkan paragraf; teks selalu di-escape (isi dari dashboard adalah teks polos). --}}
									@foreach (preg_split('/
{2,}/', $body) as $paragraph)
										<p>{{ $paragraph }}</p>
									@endforeach
								</div>
							</details>
						@endforeach
					</div>
				</article>
			@endforeach
		</section>
	</main>
@endsection
