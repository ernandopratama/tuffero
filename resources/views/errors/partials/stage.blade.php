{{-- Isi halaman galat: angka, maskot yang mengintip, dan kartu pesan. Dipakai errors/layout dan toko (404). --}}
@php
	$variant = ['404' => 'look', '403' => 'hand', '419' => 'clock', '429' => 'hand', '500' => 'dizzy', '503' => 'sleep'][$code] ?? 'look';
	$actionKeys = ['404' => ['home', 'products'], '403' => ['home'], '419' => ['home', 'cart'], '429' => ['reload', 'home'], '500' => ['reload', 'home'], '503' => ['reload']][$code] ?? ['home'];
	$hrefs = ['home' => '/', 'products' => '/produk', 'cart' => '/keranjang'];
	$digits = str_split((string) $code);
	$retry = $code === '429' ? (int) ($retryAfter ?? 0) : 0;
@endphp
<main class="err">
	<p class="err-code" role="img" aria-label="{{ trans('errors.code', ['code' => $code], $loc) }}">
		@foreach ($digits as $digit)
			<span aria-hidden="true" style="--i: {{ $loop->index }}" @if ($loop->index === 1) class="err-o" @endif>{{ $digit }}</span>
		@endforeach
	</p>
	<div class="err-stage">
		@include('errors.partials.mascot', ['variant' => $variant])
	</div>
	<section class="err-card" aria-labelledby="err-title">
		<h1 id="err-title" class="err-title">{{ trans("errors.$code.title", [], $loc) }}</h1>
		<p class="err-text">{{ trans("errors.$code.text", [], $loc) }}@if ($retry > 0) {{ trans('errors.retry_in', ['seconds' => $retry], $loc) }}@endif</p>
		<div class="err-actions">
			@foreach ($actionKeys as $key)
				@if ($key === 'reload')
					<button type="button" class="err-btn {{ $loop->first ? 'is-primary' : '' }}" onclick="location.reload()">{{ trans('errors.reload', [], $loc) }}</button>
				@else
					<a href="{{ $hrefs[$key] }}" class="err-btn {{ $loop->first ? 'is-primary' : '' }}">{{ trans("errors.$key", [], $loc) }}</a>
				@endif
			@endforeach
		</div>
	</section>
</main>
