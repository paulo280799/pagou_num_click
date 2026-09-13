<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>Pagou num click</title>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f8f8f8; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .modal { background: white; width: 350px; padding: 20px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); text-align: center; }
            .logo { width: 100%; max-width: 200px; display: block; margin: 0 auto 10px; }
            .timer { color: gray; margin-bottom: 15px; }
            .qr-code { width: 200px; height: 200px; margin: 0 auto 15px; }
            .instruction { font-weight: bold; margin-bottom: 10px; }
            .info { font-size: 14px; color: gray; margin-bottom: 15px; }
            .value { font-size: 18px; font-weight: bold; margin-top: 15px; }
            .copy-area { margin-top: 15px; padding: 10px; background: #f0f0f0; border-radius: 5px; text-align: center; font-size: 14px; word-break: break-all; }
            .copy-btn { margin-top: 10px; padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; }
            .success { color: green; font-weight: bold; font-size: 18px; }
            .expired-message { color: red; font-weight: bold; }
        </style>
    </head>
    <body>
        {{ $slot }}
    </body>
</html>
