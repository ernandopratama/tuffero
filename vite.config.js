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
            fonts: [
                bunny('Be Vietnam Pro', {
                    weights: [400, 500, 600, 700],
                }),
                bunny('EB Garamond', {
                    weights: [400, 500, 600],
                    styles: ['normal', 'italic'],
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
