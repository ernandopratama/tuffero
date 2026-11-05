@php
	$cs = trans('store.customerService');
	$csOpen = $site->openStatus()['open'];
@endphp
<script type="application/json" id="tf-chat-data">{{ $site->json(['words' => $cs, 'links' => config('store_catalog.answer_links'), 'whatsapp' => $site->whatsappUrl(), 'newTab' => $t['aria']['newTab']]) }}</script>
<div x-data="chatWidget" style="display: contents">
	<!-- Latar gelap berbur hanya tampil di layar HP (CSS). Mengetuknya memperkecil chat, sama seperti tombol Perkecil. -->
	<div class="cs-backdrop" :class="backdropClass" aria-hidden="true" @click="minimize"></div>
	<button x-ref="launcher" type="button" class="cs-launcher" data-status="idle" :data-status="status" :data-attention="attentionValue" aria-label="{{ $t['aria']['chat'] }}" aria-haspopup="dialog" aria-expanded="false" :aria-expanded="isOpen" :inert="isOpen" @click="open">
		<span class="cs-floor" aria-hidden="true"></span>
		<span x-ref="mascot" class="cs-mascot" aria-hidden="true">
			<span class="cs-react">
				<span class="cs-glow"></span>
				<span class="cs-ripple"></span>
				<span class="cs-float">
					<img src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="" decoding="async" draggable="false">
					<!-- Koordinat mengikuti gambar sumber maskot (915 x 1079), jadi ekspresi menempel di wajah pada ukuran apa pun. -->
					<svg class="cs-face" viewBox="0 0 915 1079" fill="none" stroke-linecap="round" stroke-linejoin="round">
						<g class="cs-eye cs-eye-left">
							<ellipse cx="430" cy="372" rx="86" ry="120" transform="rotate(-16 430 372)" fill="#F2882E" />
							<path d="M362 402 Q430 318 498 390" stroke="#2B1410" stroke-width="24" />
						</g>
						<g class="cs-eye cs-eye-right">
							<ellipse cx="602" cy="340" rx="80" ry="110" transform="rotate(-18 602 340)" fill="#F2882E" />
							<path d="M538 366 Q602 284 664 352" stroke="#2B1410" stroke-width="24" />
						</g>
						<g class="cs-mouth">
							<path d="M506 524 Q554 546 602 518 Q610 604 556 610 Q502 610 506 524 Z" fill="#2B1410" stroke="#2B1410" stroke-width="10" />
							<ellipse cx="556" cy="586" rx="26" ry="16" fill="#E8583F" />
						</g>
						<g class="cs-waves" stroke="#C6A15B" stroke-width="22">
							<path d="M6 760 Q-34 820 -6 884" />
							<path d="M-58 744 Q-104 820 -66 900" />
						</g>
						<g class="cs-sparks">
							<path class="cs-spark" d="M748 78 L788 88 L724 196 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
							<path class="cs-spark" d="M856 148 L888 182 L778 244 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
							<path class="cs-spark" d="M884 284 L888 318 L796 304 Z" fill="#FFB81F" stroke="#FFFFFF" stroke-width="44" paint-order="stroke" />
						</g>
					</svg>
				</span>
			</span>
		</span>
	</button>
	<section x-ref="panel" x-show="panelVisible" class="cs-panel" role="dialog" aria-labelledby="cs-panel-title" tabindex="-1" style="display: none" @keydown.esc="minimize">
		<svg class="cs-panel-sparks" viewBox="0 0 40 34" aria-hidden="true">
			<path d="M8 2 L13 3 L7 18 Z" /><path d="M31 9 L35 13 L19 22 Z" />
		</svg>
		<header class="cs-head">
			<img x-ref="avatar" class="cs-avatar" :class="avatarClass" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="" loading="lazy">
			<div class="cs-head-text">
				<h2 id="cs-panel-title" class="cs-title">{{ $cs['title'] }}</h2>
				<p class="cs-status"><span @class(['cs-dot', 'is-open' => $csOpen]) aria-hidden="true"></span>{{ $csOpen ? $cs['statusOpen'] : $cs['statusClosed'] }}</p>
			</div>
			<button type="button" class="cs-head-btn" aria-label="{{ $cs['minimize'] }}" @click="minimize">{{ $site->lineIcon('minus', 20) }}</button>
			<button type="button" class="cs-head-btn" aria-label="{{ $cs['close'] }}" @click="closeAndReset">{{ $site->lineIcon('close', 20) }}</button>
		</header>

		<div x-ref="log" class="cs-log" role="log" aria-live="polite" aria-label="{{ $cs['log'] }}" tabindex="0">
			<template x-for="m in visibleMessages" :key="m.index">
				<div class="cs-msg" :class="isUser(m) ? 'cs-msg-user' : ''">
					<template x-if="isUser(m)"><div class="cs-bubble" x-text="m.text"></div></template>
					<template x-if="isCs(m)"><img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt=""></template>
					<template x-if="isCs(m)">
						<div class="cs-bubble">
							<p x-text="answerText(m)"></p>
							<template x-for="link in linksFor(m)" :key="link.key">
								<a class="cs-link" :href="link.href" :target="link.target" :rel="link.rel"><span x-text="link.label"></span><template x-if="link.external"><span class="cs-sr"> ({{ $t['aria']['newTab'] }})</span></template></a>
							</template>
						</div>
					</template>
				</div>
			</template>
			<template x-if="typing">
				<div class="cs-msg">
					<img class="cs-msg-avatar" src="/images/mascot/tuffero-cs-body.webp" width="192" height="226" alt="">
					<div class="cs-bubble cs-typing" role="status" aria-label="{{ $cs['typing'] }}"><i></i><i></i><i></i></div>
				</div>
			</template>
			<template x-if="hasMessages">
				<div class="cs-chips" :class="chipsClass" role="group" aria-label="{{ $cs['quickReplies'] }}">
					@foreach (config('store_catalog.quick_replies') as $reply)
						<button type="button" class="cs-chip" :disabled="typing" @click="choose('{{ $reply['key'] }}', labelFor('{{ $reply['key'] }}'))">{{ $site->lineIcon($reply['icon'], 16) }}{{ $cs['labels'][$reply['key']] }}</button>
					@endforeach
				</div>
			</template>
		</div>

		<form class="cs-form" @submit.prevent="submit">
			<textarea x-ref="input" x-model="draft" class="cs-input" rows="1" maxlength="500" aria-label="{{ $cs['inputLabel'] }}" placeholder="{{ $cs['placeholder'] }}" @keydown.enter="onEnter"></textarea>
			<button type="submit" class="cs-send" :class="sendClass" :disabled="!canSend" aria-label="{{ $cs['send'] }}">{{ $site->lineIcon('send', 18) }}</button>
		</form>
		<p class="cs-notice">{{ $cs['notice'] }}</p>
	</section>
</div>
