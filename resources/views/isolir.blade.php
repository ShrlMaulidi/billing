<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Layanan Diisolir · {{ $p['isolir_nama_isp'] }}</title>
    {{-- Tanpa aset eksternal: pelanggan isolir hanya bisa mengakses server billing --}}
    <style>
        :root { --bg: #f5f6fa; --card: #fff; --text: #2b2b3a; --muted: #6c6f80; --accent: #7366ff; --warn: #f73164; --line: #e8e9f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1d1e26; --card: #262932; --text: #e8e9f0; --muted: #a3a6b4; --line: #374050; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.55; }
        .card { width: 100%; max-width: 480px; background: var(--card); border-radius: 16px; padding: 28px 24px; box-shadow: 0 8px 30px rgba(0, 0, 0, .08); text-align: center; }
        .icon { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; background: rgba(247, 49, 100, .12); color: var(--warn); }
        h1 { font-size: 1.35rem; margin: 0 0 4px; }
        .isp { color: var(--muted); font-size: .95rem; margin: 0 0 18px; }
        .pesan { margin: 0 0 20px; }
        .bayar { text-align: left; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin: 0 0 20px; white-space: pre-line; }
        .bayar strong { display: block; margin-bottom: 4px; }
        .wa { display: inline-block; width: 100%; padding: 12px 16px; border-radius: 10px; background: #25d366; color: #fff; text-decoration: none; font-weight: 600; }
        .catatan { color: var(--muted); font-size: .8rem; margin: 16px 0 0; }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon" aria-hidden="true">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
        </div>
        <h1>Layanan Internet Diisolir</h1>
        <p class="isp">{{ $p['isolir_nama_isp'] }}</p>
        <p class="pesan">{{ $p['isolir_pesan'] }}</p>

        @if (filled($p['isolir_pembayaran']))
            <div class="bayar"><strong>Info Pembayaran</strong>{{ $p['isolir_pembayaran'] }}</div>
        @endif

        @if ($wa !== '')
            <a class="wa" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Halo, internet saya diisolir. Saya ingin melakukan pembayaran.') }}">Hubungi CS via WhatsApp</a>
        @endif

        <p class="catatan">Internet akan aktif kembali setelah pembayaran dikonfirmasi.</p>
    </main>
</body>
</html>
