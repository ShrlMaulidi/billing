<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:wght@400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/cuba/css/app.css?v={{ filemtime(public_path('cuba/css/app.css')) }}">
    @viteReactRefresh
    {{-- Chunk halaman aktif ikut dimuat bersamaan dengan app.jsx (tanpa menunggu berantai) --}}
    @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
    @inertiaHead
</head>
<body>
    <script>try { if (localStorage.getItem('theme') === 'dark') document.body.classList.add('dark-only'); } catch (e) {}</script>
    @inertia
</body>
</html>
