{{-- Toast kanan atas untuk semua halaman toko. Daftar toast dari Alpine.store('toast') (resources/js/store/toast.js). --}}
<div class="tf-toaster" x-data>
	<template x-for="t in $store.toast.items" :key="t.id">
		<div class="tf-toast" :class="t.cls" :style="t.style" :role="t.role" @mouseenter="$store.toast.pause(t.id)" @mouseleave="$store.toast.resume(t.id)" @focusin="$store.toast.pause(t.id)" @focusout="$store.toast.resume(t.id)">
			<span class="tf-toast-icon" aria-hidden="true">
				<span :hidden="!t.isSuccess">{{ $site->lineIcon('check', 20) }}</span>
				<span hidden :hidden="!t.isError">{{ $site->lineIcon('close', 20) }}</span>
				<span hidden :hidden="!t.isInfo">{{ $site->lineIcon('info', 20) }}</span>
			</span>
			<div class="tf-toast-body">
				<strong x-text="t.title"></strong>
				<p x-text="t.text"></p>
			</div>
			<button type="button" class="tf-toast-close" :aria-label="$store.toast.words.close" @click="$store.toast.close(t.id)">{{ $site->lineIcon('close', 14) }}</button>
			<span class="tf-toast-timer" aria-hidden="true"></span>
		</div>
	</template>
</div>
