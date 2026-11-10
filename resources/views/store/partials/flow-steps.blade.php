{{-- Langkah pemesanan (FigCraft 04, 05, 06). $steps: label; $label: nama daftar; $current: indeks langkah aktif (statis), atau null agar diatur Alpine lewat flowClass(i)/flowCurrent(i). $variant: 'stack' (label di bawah, layar 04) atau 'inline' (layar 05/06). --}}
@php($variant ??= 'inline')
<nav class="tf-flow is-{{ $variant }}" aria-label="{{ $label }}">
	<ol>
		@foreach ($steps as $i => $step)
			@if (isset($current))
				<li @class(['is-done' => $i < $current, 'is-now' => $i === $current]) @if ($i === $current) aria-current="step" @endif>
			@else
				<li :class="flowClass({{ $i }})" :aria-current="flowCurrent({{ $i }})">
			@endif
				<span class="tf-flow-dot" aria-hidden="true"><b>{{ $i + 1 }}</b>{{ $site->lineIcon('check', 14) }}</span>
				<span class="tf-flow-label">{{ $i + 1 }}. {{ $step }}</span>
			</li>
		@endforeach
	</ol>
</nav>
