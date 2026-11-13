<script setup>
// 404 area admin: alamat /admin/... yang tidak dikenal. Kerangka admin (menu samping) tetap tampil.
</script>

<template>
  <section class="mx-auto flex max-w-md flex-col items-center py-12 text-center">
    <p class="font-heading text-[7rem] leading-none font-semibold text-primary" role="img" aria-label="Kode galat 404">
      <span class="not-found-digit">4</span><span class="not-found-digit text-[#CF6F30]" style="--i: 1">0</span><span class="not-found-digit" style="--i: 2">4</span>
    </p>
    <img src="/images/mascot/tuffero-error.webp" alt="" width="165" height="210" class="not-found-mascot mt-2 h-auto w-40" />
    <h1 class="mt-6 text-headline-md font-heading text-primary">Halaman tidak ditemukan</h1>
    <p class="mt-2 text-secondary">Alamat admin yang Anda buka tidak ada atau sudah dipindahkan.</p>
    <RouterLink
      :to="{ name: 'Dashboard' }"
      class="mt-6 inline-flex min-h-12 items-center justify-center rounded-full bg-primary px-6 font-semibold text-background transition-transform duration-150 active:scale-[0.97] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
    >
      Ke Dashboard
    </RouterLink>
  </section>
</template>

<style scoped>
.not-found-digit {
    display: inline-block;
    animation: not-found-rise 420ms cubic-bezier(0.23, 1, 0.32, 1) both;
    animation-delay: calc(var(--i, 0) * 60ms);
}
.not-found-mascot {
    animation: not-found-bob 3.2s ease-in-out 600ms infinite;
}
@keyframes not-found-rise {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: none; }
}
@keyframes not-found-bob {
    0%, 100% { transform: translateY(0) rotate(0); }
    50% { transform: translateY(-6px) rotate(-2deg); }
}
@media (prefers-reduced-motion: reduce) {
    .not-found-digit { animation: none; }
    .not-found-mascot { animation: none; }
}
</style>
