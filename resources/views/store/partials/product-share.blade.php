{{-- Baris "Bagikan" halaman produk. Dipakai dua kali: di bawah galeri (desktop) dan di bawah tombol beli (HP, supaya judul, harga, dan tombol beli tidak terdorong ke bawah). $class = is-gallery atau is-info; butuh $waHref, $p, dan $newTab dari halaman. --}}
<div class="tf-pd-share {{ $class ?? '' }}">
	<span>{{ $p['share'] }}</span>
	<a href="{{ $waHref }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $p['shareWa'] }} ({{ $newTab }})">
		<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"></path></svg>WhatsApp
	</a>
	@include('store.partials.slide-button', ['label' => $p['copyLink'], 'copyText' => false])
</div>
