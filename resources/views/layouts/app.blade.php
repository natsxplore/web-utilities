<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Data transfer') — {{ config('app.name', 'Web Utilities') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <header class="border-b border-zinc-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-4 sm:px-6">
            <h1 class="text-lg font-semibold tracking-tight">{{ config('app.name', 'Web Utilities') }}</h1>
            <p class="mt-1 text-sm text-zinc-500">PHP CENTRAL TO LARAVEL CENTRAL DATA CONVERSION UTILITY</p>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6">
        @yield('content')
    </main>
</body>
</html>
