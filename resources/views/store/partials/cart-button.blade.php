{{-- Tombol keranjang kartu produk. Bila SKU sudah di keranjang menjadi stepper jumlah + hapus (resources/js/store/cart.js, cartButton). $sku, $name. --}}
@php($k = trans('store.pages.cart'))
<div class="tf-card-cart" x-data="cartButton" data-sku="{{ $sku }}">
	<button type="button" class="tf-btn tf-btn-small tf-btn-block" data-add-cart="{{ $sku }}" :hidden="inCart">{{ $site->icon('shopping_bag', 16) }} {{ trans('store.pages.catalog.cart') }}</button>
	<div class="tf-card-qty" hidden :hidden="!inCart">
		<div class="tf-pd-stepper" role="group" aria-label="{{ $k['qty'] }} {{ $name }}">
			<button type="button" aria-label="{{ $k['qtyDec'] }}" :disabled="cannotDec" @click="dec">{{ $site->lineIcon('minus', 16) }}</button>
			<span aria-live="polite" x-text="qty"></span>
			<button type="button" aria-label="{{ $k['qtyInc'] }}" :disabled="cannotInc" @click="inc">{{ $site->lineIcon('plus', 16) }}</button>
		</div>
		<button type="button" class="tf-card-remove" aria-label="{{ $site->fill($k['removeLabel'], ['name' => $name]) }}" :disabled="busy" @click="remove">{{ $site->lineIcon('trash', 18) }}</button>
	</div>
</div>
