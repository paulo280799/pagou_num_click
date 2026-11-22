<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Pagou num click</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
        <style>.text-xl.font-bold{font-family:'Bricolage Grotesque','Helvetica Neue',Arial,sans-serif;letter-spacing:-.02em}</style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-[#F3F2EE] font-sans min-h-screen flex items-center justify-center" style="font-family: 'Instrument Sans', 'Helvetica Neue', Arial, sans-serif;">
        <div class="w-full max-w-[390px] min-h-screen sm:min-h-[844px] bg-[#F3F2EE] flex flex-col overflow-hidden sm:shadow-lg">
            {{ $slot }}
        </div>
    </body>
</html>
