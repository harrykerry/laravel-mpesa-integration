<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <meta name="description" content="A Laravel backend for Safaricom M-PESA Daraja: STK push, C2B, B2C, B2B, reversals, status queries, balances and QR codes.">
    <meta name="theme-color" content="#43b02a">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="mask-icon" href="/favicon.svg" color="#43b02a">

    {{-- Tailwind from the CDN: this page is documentation for the API, so
         keeping it build-free means no npm step to install the project. --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>
        {{-- Colours and type taken from Safaricom's own sites. --}}
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        saf: {
                            green: '#43b02a',
                            dark: '#389323',
                            tint: '#ecf4ea',
                            ink: '#262626',
                            body: '#474747',
                            muted: '#6b788b',
                            line: '#dee3e8',
                            bg: '#f3f6f9',
                            slate: '#2a2e36',
                            danger: '#f64e60',
                        },
                    },
                    fontFamily: {
                        sans: ['Open Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['IBM Plex Mono', 'ui-monospace', 'monospace'],
                    },
                },
            },
        }
    </script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=open-sans:400,600,700|ibm-plex-mono:400" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        pre { background: #2a2e36; }
        ::selection { background: #43b02a; color: #fff; }
        a { text-decoration-thickness: 1px; text-underline-offset: 3px; }
        [x-cloak] { display: none !important; }
        section[id] { scroll-margin-top: 5rem; }
    </style>
</head>
<body class="bg-saf-bg font-sans text-[15px] leading-relaxed text-saf-body antialiased">
    {{ $slot }}
</body>
</html>
