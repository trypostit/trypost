<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $htmlDir ?? 'ltr' }}" data-theme="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- The page is handed to Chromium as HTML, so relative asset paths resolve against the app. --}}
        <base href="{{ rtrim(config('app.url'), '/') }}/">
        <style>
            html { background-color: #ffffff; color-scheme: light; }
            {{-- Screen-reader copies (a chart's data table) sit outside the flow and would add blank pages. --}}
            @media print { .sr-only { display: none !important; } }
        </style>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
        @vite(['resources/js/print.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
