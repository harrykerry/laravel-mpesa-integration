<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <meta name="description" content="A Laravel backend for Safaricom M-PESA Daraja: STK push, C2B, B2C, B2B, reversals, status queries, balances and QR codes.">

    {{-- Tailwind from the CDN: this page is documentation for the API, so
         keeping it build-free means no npm step to install the project. --}}
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-mono:400,500|ibm-plex-sans:400,500,600" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        body { font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif; }
        code, pre { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
        a { text-decoration-thickness: 1px; text-underline-offset: 3px; }
        /* A warm, low-contrast page: easier to read a long document on than white. */
        pre { background: #2b2926; }
        ::selection { background: #cfe8da; }
    </style>
</head>
<body class="bg-[#edeae3] text-[15px] leading-relaxed text-stone-700 antialiased">
    {{ $slot }}
</body>
</html>
