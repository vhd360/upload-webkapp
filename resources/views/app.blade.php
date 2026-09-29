<!DOCTYPE html>
<html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Dateien senden · webkapp</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600|montserrat:600,700" rel="stylesheet">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/main.jsx'])
    </head>
    <body>
        <div id="root" data-max-bytes="{{ (int) config('uploads.max_bytes') }}"></div>
    </body>
</html>
