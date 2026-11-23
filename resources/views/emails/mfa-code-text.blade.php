@php
	$label = $label ?? 'Kode OTP login';
	$greeting = ($name ?? null) ? 'Halo '.$name.',' : 'Halo,';
	$intro = $intro ?? 'Masukkan kode One-Time Password (OTP) di atas untuk masuk ke dashboard Tuffero.';
	$noticeTitle = $noticeTitle ?? 'Bukan Anda yang masuk?';
	$notice = $notice ?? 'Abaikan email ini dan segera ganti password Anda. Jangan berikan kode ini kepada siapa pun, termasuk yang mengaku dari Tuffero.';
@endphp
{{ $label }} Tuffero Anda:

{{ $code }}

{{ $greeting }} {{ $intro }} Berlaku {{ $minutes }} menit dan hanya bisa dipakai sekali.

{{ $noticeTitle }} {{ $notice }}

Tuffero