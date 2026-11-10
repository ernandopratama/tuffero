{{-- Daftar item pesanan (FigCraft 05/06). Nama, varian, harga dari snapshot pesanan; foto dari produk bila masih ada. --}}
<ul class="tf-co-items is-compact">
	<template x-for="item in order.items" :key="item.sku">
		<li>
			<span class="tf-co-photo">
				<template x-if="item.photo"><img :src="photoUrl(item)" alt="" width="56" height="56" loading="lazy" decoding="async"></template>
			</span>
			<span class="tf-co-item-info">
				<span class="tf-co-item-name" x-text="item.product_name"></span>
				<template x-if="hasVariant(item)"><span class="tf-co-item-variant" x-text="item.variant_name"></span></template>
				<span class="tf-co-item-qty" x-text="qtyEach(item)"></span>
			</span>
			<span class="tf-co-item-price"><strong x-text="rupiah(item.line_total)"></strong></span>
		</li>
	</template>
</ul>
