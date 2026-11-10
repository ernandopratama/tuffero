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
								<a class="cs-link" :class="link.key === 'whatsapp' ? 'cs-wa' : ''" :href="link.href" :target="link.target" :rel="link.rel" :aria-label="link.aria" :data-tip="link.tip"><template x-if="link.key === 'whatsapp'"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg></template><template x-if="link.key !== 'whatsapp'"><span x-text="link.label"></span></template><template x-if="link.external && link.key !== 'whatsapp'"><span class="cs-sr"> ({{ $t['aria']['newTab'] }})</span></template></a>
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
			<template x-if="hasMessages && !isCompact">
				<div class="cs-chips" :class="chipsClass" role="group" aria-label="{{ $cs['quickReplies'] }}">
					@foreach (config('store_catalog.quick_replies') as $reply)
						<button type="button" class="cs-chip" :disabled="typing" @click="choose('{{ $reply['key'] }}', labelFor('{{ $reply['key'] }}'))">{{ $site->lineIcon($reply['icon'], 16) }}{{ $cs['labels'][$reply['key']] }}</button>
					@endforeach
					<template x-if="suggested.length"><p class="cs-chips-label">{{ $cs['faqHeading'] }}</p></template>
					<template x-for="faq in suggested" :key="faq.id">
						<button type="button" class="cs-chip cs-chip-faq" :disabled="typing" @click="askFaq(faq)" x-text="faq.title"></button>
					</template>
				</div>
			</template>
			<form class="cs-flow" x-show="isTrack" style="display: none" @submit.prevent="submitTrack" novalidate>
				<label class="cs-field"><span>{{ $cs['flow']['orderNumber'] }}</span>
					<input type="text" x-model="orderNumber" maxlength="20" autocomplete="off" autocapitalize="characters" placeholder="{{ $cs['flow']['orderNumberHint'] }}">
				</label>
				<label class="cs-field"><span>{{ $cs['flow']['contact'] }}</span>
					<input type="text" x-model="contact" maxlength="190" autocomplete="off" inputmode="email">
				</label>
				<p class="cs-flow-error" role="alert" x-show="formError" x-text="formError"></p>
				<div class="cs-flow-actions">
					<button type="submit" class="cs-flow-submit" :disabled="busy" x-text="submitLabel"></button>
					<button type="button" class="cs-flow-cancel" @click="endFlow">{{ $cs['flow']['cancel'] }}</button>
				</div>
			</form>
			<form class="cs-flow" x-show="isRates" style="display: none" @submit.prevent="submitRates" novalidate>
				<label class="cs-field"><span>{{ $cs['flow']['product'] }}</span>
					<select x-model="sku">
						<option value="">{{ $cs['flow']['chooseProduct'] }}</option>
						<template x-for="product in products" :key="product.sku"><option :value="product.sku" x-text="product.label"></option></template>
					</select>
				</label>
				<div class="cs-field-row">
					<label class="cs-field"><span>{{ $cs['flow']['quantity'] }}</span>
						<input type="number" x-model="quantity" min="1" max="999" inputmode="numeric">
					</label>
					<label class="cs-field"><span>{{ $cs['flow']['postal'] }}</span>
						<input type="text" x-model="postal" maxlength="5" inputmode="numeric" autocomplete="postal-code">
					</label>
				</div>
				<p class="cs-flow-error" role="alert" x-show="formError" x-text="formError"></p>
				<div class="cs-flow-actions">
					<button type="submit" class="cs-flow-submit" :disabled="busy" x-text="submitLabel"></button>
					<button type="button" class="cs-flow-cancel" @click="endFlow">{{ $cs['flow']['cancel'] }}</button>
				</div>
			</form>
		</div>

		<!-- Setelah percakapan berjalan, menu dan FAQ dipindah ke sini dan dirapatkan: dua tombol, masing-masing membuka satu baris tombol yang bisa digeser. -->
		<div class="cs-dock" x-show="isCompact" style="display: none">
			<div class="cs-dock-row" id="cs-dock-row" x-show="dock" x-transition.opacity.duration.150ms role="group" :aria-label="dock === 'faq' ? c.faqHeading : c.quickReplies" @wheel="onDockWheel">
				<template x-if="dock === 'menu'">
					<div class="cs-dock-chips">
						@foreach (config('store_catalog.quick_replies') as $reply)
							<button type="button" class="cs-chip" :disabled="typing" @click="choose('{{ $reply['key'] }}', labelFor('{{ $reply['key'] }}'))">{{ $site->lineIcon($reply['icon'], 16) }}{{ $cs['labels'][$reply['key']] }}</button>
						@endforeach
					</div>
				</template>
				<template x-if="dock === 'faq'">
					<div class="cs-dock-chips">
						<template x-for="faq in suggested" :key="faq.id">
							<button type="button" class="cs-chip cs-chip-faq" :disabled="typing" @click="askFaq(faq)" x-text="faq.title"></button>
						</template>
					</div>
				</template>
			</div>
			<div class="cs-dock-tabs">
				<button type="button" class="cs-dock-tab" aria-controls="cs-dock-row" :aria-expanded="dock === 'menu'" @click="toggleDock('menu')">{{ $cs['quickReplies'] }}</button>
				<template x-if="suggested.length"><button type="button" class="cs-dock-tab" aria-controls="cs-dock-row" :aria-expanded="dock === 'faq'" @click="toggleDock('faq')">{{ $cs['faqShort'] }}</button></template>
			</div>
		</div>
		<form class="cs-form" @submit.prevent="submit">
			<textarea x-ref="input" x-model="draft" class="cs-input" rows="1" maxlength="500" aria-label="{{ $cs['inputLabel'] }}" placeholder="{{ $cs['placeholder'] }}" @keydown.enter="onEnter"></textarea>
			<button type="submit" class="cs-send" :class="sendClass" :disabled="!canSend" aria-label="{{ $cs['send'] }}">{{ $site->lineIcon('send', 18) }}</button>
		</form>
		<p class="cs-notice"><span x-text="ai ? c.noticeAi : c.notice">{{ $cs['notice'] }}</span><template x-if="ai"><span> <a class="cs-notice-link" href="{{ $site->route('contact') }}#privasi">{{ $cs['privacyLink'] }}</a></span></template></p>
	</section>
</div>
