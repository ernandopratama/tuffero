<section class="tf-wrap tf-page-section">
	<div class="tf-page-cta wow fadeInUp">
		<h2>{{ $site->rich($pg['ctaTitle']) }}</h2>
		<div>
			<a href="{{ $site->route('products') }}" class="tf-btn tf-btn-light">{{ $t['hero']['shop'] }}</a>
			<a href="{{ $site->route('contact') }}" class="tf-page-cta-link">{{ $t['how']['contact'] }}</a>
		</div>
	</div>
</section>
