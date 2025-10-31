import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwind from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/css/filament/admin/theme.css',
        'resources/css/filament/inline-clean.css',
      ],
      refresh: true,
    }),
    tailwind(),
  ],
})