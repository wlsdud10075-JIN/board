@php
    $cur = $breakdown['currency'] ?? 'USD';
    $sym = ['KRW' => '₩', 'USD' => '$', 'EUR' => '€'][$cur] ?? '';
    $fmt = fn ($n) => $n === null ? '—' : $sym.number_format($n);

    // board 자체 미디어 + ssancar CDN 미디어 합본 렌더.
    $boardPhotos = $media->reject(fn ($m) => $m['video']);
    $boardVideos = $media->filter(fn ($m) => $m['video']);
    $sVideos = $ssancarMedia['videos'] ?? [];
    $sPhotos = $ssancarMedia['photos'] ?? [];
    $hasAny = $media->count() || count($sVideos) || count($sPhotos);
    $hasVideo = $boardVideos->count() || count($sVideos);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $company }} · {{ $listing->vehicle_number }}</title>

    {{-- OG 미리보기 — 카톡/왓츠앱에 링크 붙으면 견적카드 이미지 + 차량/총액이 펼쳐 보임. --}}
    @php
        $ogDesc = trim(sprintf('Car %s · Shipping %s · Total %s', $fmt($breakdown['car'] ?? null), $fmt($breakdown['shipping'] ?? null), $fmt($breakdown['total'] ?? null)));
    @endphp
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $company }}">
    <meta property="og:title" content="{{ $company }} Quotation · {{ $listing->vehicle_number }}">
    <meta property="og:description" content="{{ $ogDesc }}">
    <meta property="og:image" content="{{ $cardUrl }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ url()->full() }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $company }} Quotation · {{ $listing->vehicle_number }}">
    <meta name="twitter:description" content="{{ $ogDesc }}">
    <meta name="twitter:image" content="{{ $cardUrl }}">
    <style>
        :root { --p: #7c6fcd; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #111827; -webkit-text-size-adjust: 100%; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 0 0 40px; }
        header { background: var(--p); color: #fff; padding: 20px 20px 18px; }
        header h1 { font-size: 22px; letter-spacing: .5px; }
        header .veh { margin-top: 6px; font-size: 15px; opacity: .92; }
        /* overflow:hidden 제거 — iOS 사파리는 상위 overflow:hidden 시 영상 전체화면이 즉시 튕김 */
        .card { background: #fff; margin: 16px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .quote { padding: 16px 18px; }
        .quote .row { display: flex; justify-content: space-between; padding: 7px 0; font-size: 15px; }
        .quote .row span:first-child { color: #6b7280; }
        .quote .row.total { border-top: 1px solid #e5e7eb; margin-top: 6px; padding-top: 12px; font-weight: 700; font-size: 18px; }
        .quote .row.total span:last-child { color: var(--p); }
        .sec-title { font-size: 13px; font-weight: 700; color: #374151; padding: 4px 18px 0; }
        .media { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px 16px 16px; }
        .media img, .media video { width: 100%; border-radius: 8px; background: #000; display: block; aspect-ratio: 4/3; object-fit: cover; }
        /* ssancar Bunny 영상 임베드 — 반응형 16:9 (iframe 전체화면은 Bunny 플레이어가 처리). */
        .video-embed { position: relative; margin: 10px 16px 0; padding-bottom: 56.25%; height: 0; border-radius: 8px; overflow: hidden; background: #000; }
        .video-embed iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        .empty { padding: 24px; text-align: center; color: #9ca3af; font-size: 14px; }
        .hint { padding: 0 18px 14px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
        footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 18px; }
        @media (max-width: 420px) { .media { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>{{ $company }} · QUOTATION</h1>
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
            @if ($hasVideo)
                <p class="hint">▶ If a video won't play in fullscreen, open this page in your browser (Chrome / Safari). Some in-app chat browsers block video fullscreen.</p>
            @endif
            @if ($hasAny)
                {{-- ssancar 검차 영상 (Bunny iframe) — 다운로드/재업로드 없이 임베드(용량문제 회피). --}}
                @foreach ($sVideos as $v)
                    @if (! empty($v['embed_url']))
                        <div class="video-embed">
                            <iframe src="{{ $v['embed_url'] }}" loading="lazy"
                                    allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"
                                    allowfullscreen></iframe>
                        </div>
                    @elseif (! empty($v['url']))
                        {{-- source=local 폴백: 직접 영상 파일. --}}
                        <div style="margin: 10px 16px 0;"><video src="{{ $v['url'] }}" controls preload="metadata" style="width:100%;border-radius:8px;background:#000;display:block;"></video></div>
                    @endif
                @endforeach

                <div class="media">
                    @foreach ($boardVideos as $m)
                        {{-- playsinline 제거 — iOS 는 탭 재생 시 네이티브 전체화면(인라인 전체화면 버튼 즉시 튕김 회피). 안드로이드/PC 무영향. --}}
                        <video src="{{ $m['url'] }}" controls preload="metadata"></video>
                    @endforeach
                    @foreach ($boardPhotos as $m)
                        <img src="{{ $m['url'] }}" loading="lazy" alt="">
                    @endforeach
                    @foreach ($sPhotos as $u)
                        <img src="{{ $u }}" loading="lazy" alt="">
                    @endforeach
                </div>
            @else
                <div class="empty">No media available.</div>
            @endif
        </div>

        <footer>{{ $company }}</footer>
    </div>
</body>
</html>
