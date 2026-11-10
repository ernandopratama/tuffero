<script setup>
import { ref } from 'vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import Contents from './Contents.vue';
import GalleryPhotos from './GalleryPhotos.vue';

// Satu halaman untuk semua isi Galeri toko (D-083): foto grid dan video "Di Balik Layar".
const TABS = [{ key: 'photos', label: 'Foto' }, { key: 'videos', label: 'Video' }];
const tab = ref('photos');

function tabKeys(event) {
    if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    tab.value = tab.value === 'photos' ? 'videos' : 'photos';
    document.getElementById(`gallery-tab-${tab.value}`)?.focus();
}
</script>

<template>
  <PageHeader title="Galeri Website" description="Foto grid dan video &quot;Di Balik Layar&quot; yang tampil di halaman Galeri toko, urut menurut angka urutan." />

  <div class="mt-space-md grid w-full max-w-xs grid-cols-2 rounded-full bg-secondary-tint-12 p-1" role="tablist" aria-label="Isi galeri" @keydown="tabKeys">
    <button
      v-for="t in TABS"
      :id="`gallery-tab-${t.key}`"
      :key="t.key"
      type="button"
      role="tab"
      :aria-selected="tab === t.key"
      :aria-controls="`gallery-panel-${t.key}`"
      :tabindex="tab === t.key ? 0 : -1"
      class="min-h-10 rounded-full px-3 text-label-md transition-colors duration-[160ms] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      :class="tab === t.key ? 'bg-surface text-primary shadow-sm' : 'text-secondary hover:text-primary'"
      @click="tab = t.key"
    >{{ t.label }}</button>
  </div>

  <div id="gallery-panel-photos" role="tabpanel" aria-labelledby="gallery-tab-photos" class="mt-space-md" :hidden="tab !== 'photos'">
    <GalleryPhotos v-if="tab === 'photos'" />
  </div>
  <div id="gallery-panel-videos" role="tabpanel" aria-labelledby="gallery-tab-videos" class="mt-space-md" :hidden="tab !== 'videos'">
    <Contents v-if="tab === 'videos'" scope="video" embedded />
  </div>
</template>
