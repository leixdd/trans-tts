<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }} — {{ config('app.name') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
        <div class="pointer-events-none fixed inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_rgba(15,118,110,0.12),_transparent_55%),linear-gradient(180deg,#f5f5f4_0%,#e7e5e4_100%)]"></div>

        <main class="px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
            {{ $slot }}
        </main>

        @livewireScripts
    </body>
</html>
