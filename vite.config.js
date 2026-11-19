import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/admin.css', 'resources/js/app.js', 'resources/js/store.js'],
            refresh: true,
            // preload dimatikan: Bunny mengirim woff2 dan woff, dan plugin menulis tiap berkas sebagai @font-face sendiri
            // dengan unicode-range yang sama. Browser memakai aturan terakhir (woff), jadi woff2 yang di-preload tidak
            // pernah dipakai dan browser memberi peringatan "preloaded with link preload was not used" (laravel-vite-plugin 3.2.0).
            // Tinjau lagi setelah plugin hanya menulis woff2.
            fonts: [
                bunny('Be Vietnam Pro', {
                    weights: [400, 500, 600, 700],
                    preload: false,
                }),
                bunny('EB Garamond', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
                    preload: false,
                }),
            ],
        }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
