<section class="tf-wrap tf-page-hero">
	<div class="tf-page-hero-box">
		<img src="{{ $photo }}" alt="" fetchpriority="high">
		<div class="tf-page-hero-shade"></div>
		<div class="tf-page-hero-content">
			<nav class="tf-crumbs wow fadeInUp" aria-label="{{ $t['aria']['breadcrumb'] }}">
				<ol>
					<li><a href="/">{{ $t['nav'][0] }}</a></li>
					<li aria-current="page">{{ $current }}</li>
				</ol>
			</nav>
			<p class="tf-eyebrow tf-eyebrow-gold wow fadeInUp">{{ $hero['eyebrow'] }}</p>
			<h1 class="tf-h1 text-anime-style-2" data-cursor="-opaque">{{ $site->rich($hero['title']) }}</h1>
			<p class="tf-page-hero-text wow fadeInUp" data-wow-delay="0.1s">{{ $hero['text'] }}</p>
		</div>
	</div>
</section>
