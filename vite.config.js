import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/sass/app-ltr.scss',
                'resources/sass/app-rtl.scss',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],
});
