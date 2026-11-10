@extends('store.layouts.app')

@php
	$rowPhotos = ['Nastar Classic 1', 'Macaron New Packing 1', 'Pia premium 2', null];
	$collabPhotos = ['Terasi King 1', 'Sambal Bebek 1'];
	$products = $site->route('products');
	$rowLinks = [$products, "{$products}?q=paket", $products, "{$products}?kategori=collab"];
@endphp

@section('content')
	<main>
		@include('store.partials.page-hero', ['hero' => $p['hero'], 'current' => $t['nav'][2], 'photo' => $site->productPhoto('Macaron isi 50 2')])

		<section class="tf-page-section tf-wrap tf-svc-list">
			@foreach ($rows as $i => $row)
				<article id="{{ $site::SERVICE_IDS[$i] }}" class="tf-svc">
					@if ($rowPhotos[$i])
						<figure class="image-anime wow fadeInUp"><img src="{{ $site->productPhoto($rowPhotos[$i]) }}" alt="{{ $row['photo'] }}" loading="lazy"></figure>
					@else
						<figure class="tf-svc-duo image-anime wow fadeInUp" role="group" aria-label="{{ $row['photo'] }}">@foreach ($collabPhotos as $k => $name)<img src="{{ $site->productPhoto($name) }}" alt="{{ $row['photos'][$k] }}" loading="lazy">@endforeach</figure>
					@endif
					<div class="tf-svc-copy">
						<span class="tf-svc-num wow fadeInUp">0{{ $i + 1 }}.</span>
						<h2 class="tf-h2 text-anime-style-2" data-cursor="-opaque">{{ $site->rich($row['title']) }}</h2>
						<p class="tf-lead wow fadeInUp">{{ $row['text'] }}</p>
						<ul class="wow fadeInUp" data-wow-delay="0.06s">
							@foreach ($row['points'] as $item)
								<li><span>{{ $site->icon('check', 14) }}</span>{{ $item }}</li>
							@endforeach
						</ul>
						<a href="{{ $rowLinks[$i] }}" class="tf-btn tf-btn-medium wow fadeInUp" data-wow-delay="0.1s">{{ $row['link'] }} {{ $site->icon('north_east', 12) }}</a>
					</div>
				</article>
			@endforeach
		</section>

		<!-- Cara pesan -->
		<section class="tf-page-band">
			<div class="tf-wrap">
				<div class="tf-head">
					<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $t['how']['eyebrow'] }}</p>
					<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($t['how']['title']) }}</h2>
				</div>
				<ol class="tf-step-grid">
					@foreach ($t['how']['steps'] as $i => [$title, $text])
						<li class="wow fadeInUp" data-wow-delay="{{ ($i * 0.06).'s' }}">
							<span>0{{ $i + 1 }}</span>
							<h3>{{ $title }}</h3>
							<p>{{ $text }}</p>
						</li>
					@endforeach
				</ol>
			</div>
		</section>

		<!-- Pertanyaan umum -->
		<section id="faq" class="tf-page-section tf-wrap tf-faq">
			<div class="tf-head">
				<p class="tf-eyebrow wow fadeInUp">{{ $p['faq']['eyebrow'] }}</p>
				<h2 class="tf-h2 tf-h2-tight text-anime-style-2" data-cursor="-opaque">{{ $site->rich($p['faq']['title']) }}</h2>
			</div>
			<div class="tf-faq-list wow fadeInUp">
				@foreach ($p['faq']['items'] as [$question, $answer])
					<details>
						<summary>{{ $question }}<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg></summary>
						<p>{{ $answer }}</p>
					</details>
				@endforeach
			</div>
		</section>

		@include('store.partials.page-cta')
	</main>
@endsection
