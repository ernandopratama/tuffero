@extends('store.layouts.app')

@section('content')
	<script type="application/json" id="tf-vouchers-data">{{ $site->json([...$c, 'customer' => $storeUser && ! $storeRole, 'signIn' => $site->route('account')]) }}</script>
	<main class="tf-wrap tf-shop" x-data="vouchersPage">
		<h1 class="tf-shop-title">{{ $c['title'] }}</h1>
		<p class="tf-shop-lead">{{ $c['lead'] }}</p>
		@include('store.partials.voucher-claim', ['showEmpty' => true])
		<p class="tf-voucher-mine"><a href="{{ $site->route('myVouchers') }}" class="tf-shop-link">{{ $c['mine'] }}</a></p>
	</main>
@endsection
