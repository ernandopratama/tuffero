@extends('layouts.app')

@section('title', 'Tuffero Bakery | Roti & Kue Premium')

@section('content')
{{-- Hero: placeholder foto. Ganti bg gradient dengan foto produk Tuffero asli + overlay dari design system. --}}
<section class="bg-gradient-to-b from-[rgba(43,27,20,0.68)] to-[rgba(43,27,20,0.80)] bg-primary text-background">
    <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 sm:py-28 lg:px-8 lg:py-36">
        <p class="text-xs font-bold uppercase tracking-widest text-accent">Tuffero Bakery</p>
        <h1 class="mt-4 max-w-3xl text-[2.125rem] font-semibold leading-tight sm:text-5xl lg:text-6xl">
            Roti &amp; kue <span class="font-accent italic font-medium text-accent">hangat</span>, dibuat dengan sepenuh hati
        </h1>
        <p class="mt-6 max-w-xl text-base leading-relaxed text-border">Teks placeholder. Tagline resmi menunggu persetujuan tim Tuffero.</p>
        <div class="mt-10 flex flex-wrap gap-4">
            <a href="#produk-unggulan" class="rounded-full bg-accent px-7 py-3 text-sm font-bold text-primary transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-background">Jelajahi Produk</a>
            <a href="#kategori" class="rounded-full border border-border px-7 py-3 text-sm font-bold text-background transition hover:border-accent hover:text-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-accent">Lihat Kategori</a>
        </div>
        <p class="mt-10 text-xs text-border/70">[Placeholder] Area ini disiapkan untuk foto hero asli Tuffero.</p>
    </div>
</section>

{{-- Kategori: nama kategori belum ditetapkan. --}}
<section id="kategori" class="mx-auto max-w-7xl scroll-mt-20 px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
    <h2 class="text-[1.625rem] font-semibold text-primary sm:text-4xl">Kategori</h2>
    <p class="mt-2 text-sm">Nama kategori final menunggu daftar katalog dari tim Tuffero.</p>
    <ul class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach (range(1, 4) as $i)
            <li class="flex aspect-[4/3] flex-col items-center justify-center rounded-xl border border-dashed border-border bg-surface p-4 text-center">
                <span class="font-semibold text-primary">Kategori {{ $i }}</span>
                <span class="mt-1 text-xs">Placeholder</span>
            </li>
        @endforeach
    </ul>
</section>

{{-- Produk unggulan: kartu statis, BUKAN data produk nyata. --}}
<section id="produk-unggulan" class="scroll-mt-20 border-y border-border bg-surface/50 py-16 lg:py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 class="text-[1.625rem] font-semibold text-primary sm:text-4xl">Produk Unggulan</h2>
        <p class="mt-2 text-sm">Kartu di bawah hanya placeholder. Produk, harga, dan foto asli belum tersedia.</p>
        <ul class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (range(1, 4) as $i)
                <li class="overflow-hidden rounded-xl border border-border bg-surface">
                    <div class="flex aspect-square items-center justify-center bg-background text-xs">Foto produk (placeholder)</div>
                    <div class="p-4">
                        <h3 class="text-base font-semibold text-primary">Nama Produk {{ $i }}</h3>
                        <p class="mt-1 text-sm">Deskripsi singkat produk.</p>
                        <p class="mt-3 font-bold text-primary">Rp &mdash;</p>
                        <button type="button" disabled class="mt-4 w-full cursor-not-allowed rounded-full bg-accent/50 px-4 py-2 text-sm font-bold text-primary/70">Segera Hadir</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section id="cta" class="scroll-mt-20 bg-primary py-16 text-center text-background lg:py-20">
    <div class="mx-auto max-w-2xl px-4">
        <h2 class="text-[1.625rem] font-semibold sm:text-4xl">Siap mencicipi <span class="font-accent italic text-accent">Tuffero</span>?</h2>
        <p class="mt-4 text-border">Teks placeholder. Pemesanan online sedang disiapkan.</p>
        <a href="#produk-unggulan" class="mt-8 inline-block rounded-full bg-accent px-7 py-3 text-sm font-bold text-primary transition hover:bg-accent-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-background">Jelajahi Produk</a>
    </div>
</section>
@endsection
