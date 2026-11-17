{{-- Tombol geser "Salin tautan" dan "Hubungi bantuan" (gaya .tf-slide-btn di public/css/tuffero-landing.css).
     $href = tautan (kosong = tombol salin tautan), $label = teks, $icon = nama lineIcon, $block = selebar wadah, $class = kelas tambahan. --}}
@php($classes = trim('tf-slide-btn '.(($block ?? false) ? 'is-block ' : '').($class ?? '')))
@if (! empty($href))
	<a href="{{ $href }}" class="{{ $classes }}"><span class="tf-slide-btn-text">{{ $label }}</span><span class="tf-slide-btn-fill" aria-hidden="true"></span><span class="tf-slide-btn-icon" aria-hidden="true">{{ $site->lineIcon($icon ?? 'headset', 18) }}</span></a>
@else
	{{-- $copyText = false bila komponen Alpine tidak punya `copyText` (halaman produk memberi tahu lewat toast). --}}
	<button type="button" class="{{ $classes }}" @click="copyLink"><span class="tf-slide-btn-text" @if ($copyText ?? true) x-text="copyText" @endif>{{ $label }}</span><span class="tf-slide-btn-fill" aria-hidden="true"></span><span class="tf-slide-btn-icon" aria-hidden="true">{{ $site->lineIcon($icon ?? 'copy', 18) }}</span></button>
@endif
