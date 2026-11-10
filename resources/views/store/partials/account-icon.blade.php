{{-- Ikon garis 24x24 untuk halaman akun; warna mengikuti currentColor. --}}
<svg class="tf-acct-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">@switch($name)
	@case('order')<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v8"/>@break
	@case('address')<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>@break
	@case('voucher')<path d="M3 9a3 3 0 0 0 0 6v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a3 3 0 0 1 0-6V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v3Z"/><path d="M13 5v2M13 11v2M13 17v2"/>@break
	@case('profile')<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>@break
	@case('password')<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>@break
	@case('track')<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>@break
	@case('help')<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 0 1 4.9.7c0 1.7-2.4 2.2-2.4 3.8M12 17h.01"/>@break
	@case('logout')<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/>@break
	@case('admin')<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>@break
	@case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>@break
	@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
	@case('flash')<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>@break
	@case('chevron')<path d="m9 6 6 6-6 6"/>@break
	@case('back')<path d="M19 12H5M11 18l-6-6 6-6"/>@break
	@default<path d="M5 12h14M13 6l6 6-6 6"/>
@endswitch</svg>
