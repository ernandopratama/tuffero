@php
    // Placeholder: tautan belum mengarah ke halaman nyata (katalog dan keranjang belum dibuat).
    $nav = [
        ['Beranda', url('/')],
        ['Produk', '#produk-unggulan'],
        ['Kategori', '#kategori'],
        ['Tentang', '#cta'],
    ];
@endphp
<header class="sticky top-0 z-40 bg-primary text-background">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
        {{-- Placeholder teks, BUKAN logo resmi Tuffero. Ganti dengan artwork logo asli. --}}
        <a href="{{ url('/') }}" class="text-xl font-extrabold tracking-wide focus:outline-none focus-visible:ring-2 focus-visible:ring-accent">TUFFERO</a>

        <nav aria-label="Navigasi utama" class="hidden md:block">
            <ul class="flex items-center gap-8 text-sm font-semibold">
                @foreach ($nav as [$label, $href])
                    <li><a href="{{ $href }}" class="transition hover:text-accent focus:outline-none focus-visible:text-accent focus-visible:underline">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <a href="#produk-unggulan" class="hidden rounded-full bg-accent px-5 py-2 text-sm font-bold text-primary transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-background md:inline-block">Jelajahi Produk</a>

        {{-- Menu mobile tanpa JavaScript --}}
        <details class="group relative md:hidden">
            <summary class="flex h-11 w-11 cursor-pointer list-none items-center justify-center rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-accent" aria-label="Menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
            </summary>
            <ul class="absolute right-0 mt-2 w-56 rounded-lg border border-secondary bg-primary p-2 text-sm font-semibold shadow-lg">
                @foreach ($nav as [$label, $href])
                    <li><a href="{{ $href }}" class="block rounded px-3 py-3 hover:bg-secondary focus:outline-none focus-visible:bg-secondary">{{ $label }}</a></li>
                @endforeach
            </ul>
        </details>
    </div>
</header>
