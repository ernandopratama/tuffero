<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Tuffero Bakery')</title>
    <meta name="description" content="@yield('description', 'Tuffero Bakery: roti dan kue premium, hangat, dan elegan.')">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-background font-sans text-secondary antialiased">
    <a href="#konten" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-accent focus:px-4 focus:py-2 focus:text-primary">Lewati ke konten</a>

    @include('partials.header')

    <main id="konten">
        @yield('content')
    </main>

    @include('partials.footer')
</body>
</html>
