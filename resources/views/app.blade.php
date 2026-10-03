@php($theme = data_get($page, 'props.auth.user.theme', 'light'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $htmlDir ?? 'ltr' }}" data-theme="{{ $theme }}" @class(['dark' => $theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @include('partials.gtm')

        {{-- Resolves the "system" theme before the first paint and follows the
             OS setting while the page is open. Explicit themes are rendered by
             the server; resources/js/preferences.ts keeps both in step. --}}
        <script>
            (function () {
                var root = document.documentElement;
                var media = window.matchMedia('(prefers-color-scheme: dark)');
                var apply = function () {
                    if (root.dataset.theme === 'system') {
                        root.classList.toggle('dark', media.matches);
                    }
                };
                apply();
                media.addEventListener('change', apply);
            })();
        </script>

        {{-- Inline page-paint background. Matches `--background` so the
             first paint doesn't flash before CSS loads. --}}
        <style>
            html {
                background-color: #ffffff;
                color-scheme: light;
            }

            html.dark {
                background-color: #0a0a0a;
                color-scheme: dark;
            }
        </style>

        <title data-inertia>{{ config('app.name', 'TryPost.it') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <!-- Fonts: Inter (UI body), Outfit (headings), JetBrains Mono (code/tokens). -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @include('partials.gtm-noscript')
        @inertia
    </body>
</html>
