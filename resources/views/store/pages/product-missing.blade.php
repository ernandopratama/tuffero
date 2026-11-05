@extends('store.layouts.app')

@section('content')
	<main>
		<section class="tf-wrap tf-pd-missing">
			<h1 class="tf-pd-title">{{ $p['notFoundTitle'] }}</h1>
			<p>{{ $p['notFoundText'] }}</p>
			<a href="{{ $site->route('products') }}" class="tf-btn">{{ $p['back'] }}</a>
		</section>

		@include('store.partials.trust')
	</main>
@endsection
