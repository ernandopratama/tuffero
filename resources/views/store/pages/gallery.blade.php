@extends('store.layouts.app')

@php
	// Ikon kategori (public/images/Logo/icon, versi kecil di thumb/ untuk tab).
	$tabs = ['nastar' => 'nastar', 'macaron' => 'macaron', 'pia' => 'pia', 'snack' => 'camilan'];
@endphp

@section('content')
	<main>
		@include('store.partials.page-hero', ['hero' => $p['hero'], 'current' => $t['nav'][6], 'photo' => $site->productPhoto('Macaron isi 50 3')])

		<section class="tf-wrap tf-gal-section" x-data="gallery">
			<div x-ref="tabs" class="tf-cat-tabs wow fadeInUp" role="group" aria-label="{{ $p['filter'] }}">
				<button type="button" class="tf-tab" aria-label="{{ $p['all'] }}" data-tip="{{ $p['all'] }}" aria-pressed="true" :aria-pressed="cat === 'all'" @click="select('all')"><img src="/images/Logo/icon/thumb/semua.webp" alt="" width="81" height="54" decoding="async"></button>
				@foreach ($tabs as $key => $icon)
					<button type="button" class="tf-tab" aria-label="{{ $p['cats'][$key] }}" data-tip="{{ $p['cats'][$key] }}" aria-pressed="false" :aria-pressed="cat === '{{ $key }}'" @click="select('{{ $key }}')"><img src="/images/Logo/icon/thumb/{{ $icon }}.webp" alt="" width="81" height="54" decoding="async"></button>
				@endforeach
			</div>
			<!-- .gallery-items: lightbox Magnific dari function.js, sama seperti galeri landing -->
			<div x-ref="grid" class="tf-gal-grid gallery-items">
				@foreach ($photos as $photo)
						@php $title = $photo->titleFor(app()->getLocale()); @endphp
						<a href="{{ $photo->fullUrl() }}" data-cat="{{ $photo->category }}" @class(['tf-gal-item', 'is-big' => $photo->is_featured]) data-cursor-text="{{ $t['gallery']['view'] }}" aria-label="{{ $t['gallery']['view'] }}: {{ $title }}">
							<img src="{{ $photo->imageUrl() }}" alt="{{ $title }}" width="800" height="800" loading="lazy" decoding="async">
							<span>{{ $title }}</span>
						</a>
					@endforeach
			</div>
		</section>

		@if ($liveVideos->isNotEmpty())
		<section class="tf-page-band">
			<div class="tf-wrap">
				<div class="tf-head">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $p['video']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['video']['title']) }}</h2>
				</div>
					<div x-data="reels" class="tf-reels-host" data-open-on="{{ $p['video']['openOn'] }}">
					<div class="tf-gal-reels">
						@foreach ($liveVideos as $i => $v)
							@php
								$platform = $p['video']['providers'][$v->provider] ?? 'web';
								$embed = $v->embedUrl();
							@endphp
							<a href="{{ $v->video_url }}" class="tf-reel wow fadeInUp" target="_blank" rel="noopener noreferrer" @click="open" data-embed="{{ $embed }}" data-title="{{ $v->title }}" data-platform="{{ $platform }}" data-wow-delay="{{ ($i * 0.06).'s' }}" aria-label="{{ str_replace([':title', ':platform'], [$v->title, $platform], $embed ? $p['video']['play'] : $p['video']['open']) }}">
								<img src="/storage/{{ $v->image_path }}" alt="" width="360" height="640" loading="lazy" decoding="async">
								<b>{{ $platform }}</b>
								<i aria-hidden="true">{{ $site->lineIcon('play', 22) }}</i>
								<span>{{ $v->title }}</span>
							</a>
						@endforeach
					</div>
					<dialog x-ref="dialog" class="tf-reel-dialog" aria-label="{{ $p['video']['dialog'] }}" @close="stop" @click="backdrop">
						<div class="tf-reel-box">
							<button type="button" class="tf-reel-close" aria-label="{{ $p['video']['close'] }}" @click="close">{{ $site->lineIcon('close', 20) }}</button>
							<div x-ref="frame" class="tf-reel-frame"></div>
							<a x-ref="link" href="#" class="tf-reel-open" target="_blank" rel="noopener noreferrer"><span x-text="openLabel"></span></a>
						</div>
					</dialog>
					</div>
			</div>
		</section>
		@endif

		@include('store.partials.page-cta')
	</main>
@endsection
