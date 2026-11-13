{{-- Maskot galat: kepala yang mengintip di atas kartu. Gambar dasar dan koordinat mata sama dengan LoginMascot.vue (680 x 555). --}}
{{-- Variant: look (404), hand (403, 429), clock (419), dizzy (500), sleep (503). Gerak hanya transform dan opacity. --}}
<svg class="err-mascot is-{{ $variant }}" viewBox="0 0 680 555" aria-hidden="true" focusable="false">
	@if ($variant === 'hand')
		<defs>
			<g id="err-hand-parts">
				<ellipse cx="0" cy="22" rx="76" ry="62" />
				<rect x="-70" y="-112" width="42" height="104" rx="21" transform="rotate(-6 -49 -60)" />
				<rect x="-21" y="-128" width="42" height="116" rx="21" />
				<rect x="28" y="-112" width="42" height="104" rx="21" transform="rotate(6 49 -60)" />
				<rect x="52" y="-14" width="40" height="78" rx="20" transform="rotate(-28 72 25)" />
			</g>
		</defs>
	@endif

	<image href="{{ asset('images/mascot/tuffero-login.webp') }}" width="680" height="555" />

	@if (in_array($variant, ['look', 'hand', 'clock'], true))
		<g class="err-eyes">
			<g class="err-eye">
				<ellipse cx="342" cy="350" rx="66" ry="91" transform="rotate(-17 342 350)" fill="#000" />
				<ellipse cx="342" cy="350" rx="52" ry="77" transform="rotate(-17 342 350)" fill="#fff" />
				<g class="err-pupil">
					<ellipse cx="366" cy="348" rx="37" ry="53" transform="rotate(-17 366 348)" fill="#000" />
					<circle cx="354" cy="325" r="14.5" fill="#fff" />
				</g>
			</g>
			<g class="err-eye">
				<ellipse cx="505" cy="325" rx="61" ry="86" transform="rotate(-16 505 325)" fill="#000" />
				<ellipse cx="505" cy="325" rx="47" ry="72" transform="rotate(-16 505 325)" fill="#fff" />
				<g class="err-pupil">
					<ellipse cx="522" cy="324" rx="30" ry="49" transform="rotate(-16 522 324)" fill="#000" />
					<circle cx="516" cy="299" r="13.5" fill="#fff" />
				</g>
			</g>
		</g>
	@elseif ($variant === 'dizzy')
		<g fill="none" stroke="#000" stroke-width="20" stroke-linecap="round">
			<path d="M300 308L384 392M384 308L300 392" />
			<path d="M465 285L545 365M545 285L465 365" />
		</g>
	@else
		<g fill="none" stroke="#000" stroke-width="20" stroke-linecap="round">
			<path d="M292 350Q342 392 392 350" />
			<path d="M458 326Q508 368 558 326" />
		</g>
	@endif

	@switch ($variant)
		@case('look')
			<text class="err-q" x="560" y="215" font-family="'EB Garamond', Georgia, serif" font-size="170" font-weight="600" fill="#CF6F30">?</text>
			@break
		@case('hand')
			<g class="err-hand-rise">
				<g class="err-hand-wave">
					<use href="#err-hand-parts" fill="#5C3A21" stroke="#5C3A21" stroke-width="14" stroke-linejoin="round" />
					<use href="#err-hand-parts" fill="#fff" />
				</g>
			</g>
			@break
		@case('clock')
			<g class="err-clock" transform="translate(560 150)">
				<circle r="62" fill="#fff" stroke="#5C3A21" stroke-width="12" />
				<path d="M0 -40V0" class="err-clock-hand" stroke="#CF6F30" stroke-width="10" stroke-linecap="round" />
				<path d="M0 0L24 14" stroke="#5C3A21" stroke-width="10" stroke-linecap="round" />
			</g>
			@break
		@case('dizzy')
			<path class="err-drop" d="M610 235C610 235 586 267 586 283A24 24 0 0 0 634 283C634 267 610 235 610 235Z" fill="#8CC4E8" />
			@break
		@case('sleep')
			@foreach ([['x' => 480, 'y' => 250, 's' => 70], ['x' => 540, 'y' => 190, 's' => 90], ['x' => 610, 'y' => 120, 's' => 110]] as $z)
				<text class="err-z" x="{{ $z['x'] }}" y="{{ $z['y'] }}" font-family="'EB Garamond', Georgia, serif" font-size="{{ $z['s'] }}" font-weight="600" fill="#CF6F30" style="animation-delay: {{ $loop->index * 0.9 }}s">z</text>
			@endforeach
			@break
	@endswitch
</svg>
