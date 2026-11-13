<footer id="contact" class="tf-footer">
	<div class="tf-footer-wave" aria-hidden="true">
		<i class="tf-glow"></i>
		<svg class="tf-wave tf-wave-b" viewBox="0 0 2880 160" preserveAspectRatio="none"><path d="M0,96 C240,150 480,150 720,96 C960,42 1200,42 1440,96 C1680,150 1920,150 2160,96 C2400,42 2640,42 2880,96 L2880,160 L0,160 Z"></path></svg>
		<svg class="tf-wave tf-wave-a" viewBox="0 0 2880 160" preserveAspectRatio="none"><path class="tf-wave-fill" d="M0,80 C240,10 480,10 720,80 C960,150 1200,150 1440,80 C1680,10 1920,10 2160,80 C2400,150 2640,150 2880,80 L2880,160 L0,160 Z"></path><path class="tf-wave-edge" d="M0,80 C240,10 480,10 720,80 C960,150 1200,150 1440,80 C1680,10 1920,10 2160,80 C2400,150 2640,150 2880,80"></path></svg>
	</div>
	<div class="tf-wrap tf-footer-grid">
		<div class="tf-footer-brand">
			<div>
				<img src="/images/Logo/Tuffero Script Logo White - NoBckgrnd.webp" alt="Tuffero" width="120" height="40">
				<p>{{ $t['footer']['about'] }}</p>
			</div>
			<div class="tf-footer-fine">
				<p>{{ $site->fill($t['footer']['copyright'], ['year' => now('Asia/Jakarta')->year]) }}</p>
				<div>{{-- Kunci = id bagian S&K/Privasi di halaman Kontak. --}}@foreach ($t['footer']['legal'] as $anchor => $label)<a href="/kontak#{{ $anchor }}">{{ $label }}</a>@endforeach</div>
			</div>
		</div>
		<div class="tf-footer-main">
			<div class="tf-footer-cols">
				<div class="tf-footer-col">
					<h3>{{ $t['footer']['quick'] }}</h3>
					<ul>@foreach ($t['footer']['links'] as $i => $label)<li><a href="{{ $site::FOOTER_LINKS[$i] }}">{{ $label }}</a></li>@endforeach</ul>
				</div>
				<div class="tf-footer-col">
					<h3>{{ $t['footer']['helpTitle'] }}</h3>
					<ul><li><a href="#">{{ $t['top']['track'] }}</a></li><li><a href="{{ $site->route('contact') }}">{{ $t['top']['help'] }}</a></li></ul>
				</div>
				<div class="tf-footer-col">
					<h3>{{ $t['footer']['contactTitle'] }}</h3>
					<ul>
						<li class="tf-footer-address">{{ $t['footer']['address'] }}</li>
						<li><a href="{{ $site->phoneHref() }}">{{ $site->phoneDisplay() }}</a></li>
						@foreach ($t['footer']['hours'] as $row)
							<li>{{ $row }}</li>
						@endforeach
					</ul>
				</div>
				<div class="tf-footer-col">
					<h3>{{ $t['footer']['followTitle'] }}</h3>
					<ul>
						<li><a href="{{ $site::INSTAGRAM_URL }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $site::INSTAGRAM_HANDLE }} (Instagram, {{ $t['aria']['newTab'] }})">{{ $site->lineIcon('instagram') }}<span>{{ $site::INSTAGRAM_HANDLE }}</span></a></li>
						<li><a href="{{ $site::TIKTOK_URL }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $site::TIKTOK_HANDLE }} (TikTok, {{ $t['aria']['newTab'] }})">{{ $site->lineIcon('tiktok') }}<span>{{ $site::TIKTOK_HANDLE }}</span></a></li>
					</ul>
				</div>
			</div>
			<img class="tf-footer-mark" src="/images/Logo/Logo Tuffero Bakery-Pth.webp" alt="" aria-hidden="true" width="2172" height="724" loading="lazy">
		</div>
	</div>
</footer>
