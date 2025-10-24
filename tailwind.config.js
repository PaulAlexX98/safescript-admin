export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
    './vendor/filament/**/*.blade.php',
    './app/**/*.php',
    './app/Filament/**/*.php',
  ],
  theme: { extend: {} },
  safelist: [
    'max-h-[70vh]', 'min-w-[1200px]',
    'w-[11ch]', 'w-[16rem]', 'w-[36rem]', 'w-[13rem]', 'w-[9rem]', 'w-[10rem]',
    'bg-black/40', 'divide-white/10', 'border-white/10', 'backdrop-blur',
    'text-[11px]', 'leading-5', 'tabular-nums',
    'overflow-auto', 'overflow-x-auto', 'overflow-y-auto',
    'rounded-lg', 'table-fixed', 'sticky', 'top-0', 'z-10',
    'backdrop-blur-0','backdrop-blur','backdrop-blur-sm','backdrop-blur-md',
    'backdrop-blur-lg','backdrop-blur-xl','backdrop-blur-2xl','backdrop-blur-3xl'
  ],
  plugins: [],
};