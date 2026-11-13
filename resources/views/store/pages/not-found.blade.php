@extends('store.layouts.app', ['footer' => false, 'mainbar' => false])

@section('content')
	<link rel="stylesheet" href="{{ asset('css/tuffero-error.css') }}?v={{ filemtime(public_path('css/tuffero-error.css')) }}">
	@include('errors.partials.stage', ['code' => '404', 'loc' => $locale])
@endsection
