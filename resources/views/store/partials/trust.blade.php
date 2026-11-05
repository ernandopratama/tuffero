@php $trustIcons = ['pineapple', 'truck', 'lock', 'redeem']; @endphp
<section class="tf-trust tf-line">
	<ul class="tf-wrap">
		@foreach ($t['trust'] as $i => [$title, $text])
			<li class="wow fadeInUp" data-wow-delay="{{ ($i * 0.06).'s' }}">
				<span>{{ $site->icon($trustIcons[$i], 22) }}</span>
				<div><h3>{{ $title }}</h3><p>{{ $text }}</p></div>
			</li>
		@endforeach
	</ul>
</section>
