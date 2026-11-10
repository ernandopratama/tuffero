{{-- Rincian total pesanan (FigCraft 05/06). $totalLabel: judul baris total. --}}
<dl class="tf-od-rows is-boxed">
	<div><dt>{{ $c['subtotal'] }}</dt><dd x-text="rupiah(order.subtotal)"></dd></div>
	<div><dt x-text="shippingWithText"></dt><dd x-text="rupiah(order.shipping_fee)"></dd></div>
	<template x-if="hasDiscount"><div><dt>{{ $c['discount'] }} <small x-text="order.voucher_label"></small></dt><dd x-text="discountText"></dd></div></template>
</dl>
<div class="tf-od-sum-total"><span>{{ $totalLabel }}</span><strong x-text="rupiah(order.total)"></strong></div>
