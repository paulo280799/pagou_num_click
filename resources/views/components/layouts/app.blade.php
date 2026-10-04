<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Pagou num click</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#F3F2EE] font-sans min-h-screen flex items-center justify-center" style="font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
        <div class="w-full max-w-[390px] min-h-screen sm:min-h-[844px] bg-[#F3F2EE] flex flex-col overflow-hidden sm:shadow-lg">
            {{ $slot }}
        </div>
    </body>
</html>
