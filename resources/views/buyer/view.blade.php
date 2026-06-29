@php
    $cur = $breakdown['currency'] ?? 'USD';
    $sym = ['KRW' => '₩', 'USD' => '$', 'EUR' => '€'][$cur] ?? '';
    $fmt = fn ($n) => $n === null ? '—' : $sym.number_format($n);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>SSANCAR · {{ $listing->vehicle_number }}</title>
    <style>
        :root { --p: #7c6fcd; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #111827; -webkit-text-size-adjust: 100%; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 0 0 40px; }
        header { background: var(--p); color: #fff; padding: 20px 20px 18px; }
        header h1 { font-size: 22px; letter-spacing: .5px; }
        header .veh { margin-top: 6px; font-size: 15px; opacity: .92; }
        .card { background: #fff; margin: 16px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow: hidden; }
        .quote { padding: 16px 18px; }
        .quote .row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 15px; }
        .quote .row span:first-child { color: #6b7280; }
        .quote .row.total { border-top: 1px solid #e5e7eb; margin-top: 6px; padding-top: 12px; font-weight: 700; font-size: 18px; }
        .quote .row.total span:last-child { color: var(--p); }
        .sec-title { font-size: 13px; font-weight: 700; color: #374151; padding: 4px 18px 0; }
        .media { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px 16px 16px; }
        .media img, .media video { width: 100%; border-radius: 8px; background: #000; display: block; aspect-ratio: 4/3; object-fit: cover; }
        .empty { padding: 24px; text-align: center; color: #9ca3af; font-size: 14px; }
        footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 18px; }
        @media (max-width: 420px) { .media { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>SSANCAR · QUOTATION</h1>
            <div class="veh">{{ $listing->vehicle_number }}</div>
        </header>

        @if ($breakdown)
            <div class="card quote">
                <div class="row"><span>Car Price</span><span>{{ $fmt($breakdown['car']) }}</span></div>
                <div class="row"><span>Shipping</span><span>{{ $fmt($breakdown['shipping']) }}</span></div>
                <div class="row total"><span>Total</span><span>{{ $fmt($breakdown['total']) }}</span></div>
            </div>
        @endif

        <div class="card">
            <div class="sec-title">Photos &amp; Videos</div>
            @if ($media->count())
                <div class="media">
                    @foreach ($media as $m)
                        @if ($m['video'])
                            <video src="{{ $m['url'] }}" controls preload="metadata" playsinline></video>
                        @else
                            <img src="{{ $m['url'] }}" loading="lazy" alt="">
                        @endif
                    @endforeach
                </div>
            @else
                <div class="empty">No media available.</div>
            @endif
        </div>

        <footer>SSANCAR</footer>
    </div>
</body>
</html>
