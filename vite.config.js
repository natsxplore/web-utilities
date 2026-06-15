import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: ['resources/views/**/*.blade.php'], // Only reload on Blade changes
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: [
                '**/storage/framework/views/**',
                '**/app/**/*.php',      // Ignore all app PHP files (controllers, models, etc.)
                '**/routes/**/*.php',    // Ignore route files
                '**/config/**/*.php',    // Ignore config files
                '**/database/**/*.php',  // Ignore database files
                '**/tests/**/*.php',     // Ignore test files
            ],
        },
    },
});