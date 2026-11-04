<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rulecraft Builder browser fixture</title>
    <style>
        body { margin: 0; padding: 32px; font-family: Arial, sans-serif; background: #f5f5f4; }
        main { max-width: 1240px; margin: auto; }
        .host-title { font-size: 14px; margin-bottom: 16px; color: #57534e; }
        @media(max-width: 600px) { body { padding: 8px; } }
    </style>
</head>
<body>
<main>
    <p class="host-title">Application settings / Approval policies</p>
    <x-rulecraft::builder :group="request()->query('group')" />
    @if (request()->boolean('multiple'))
        <x-rulecraft::builder />
    @endif
</main>
</body>
</html>
