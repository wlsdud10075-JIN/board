{{--
    탁송 배차 요청 — 업체 기사가 알림톡 버튼(서명 링크)에서 연다. 로그인 없음·폰 폭 기준(claim-approval/show 와 같은 결).
    사진(명함·외관)·주소·문구·도착지 → [배차완료]/[배차거절(사유 선택)]. GET 은 표시만, 결정은 60분 서명 POST(선착순).
--}}
@php
    $listing = $req->listing;
    $plate = \App\Support\Plate::show($listing?->vehicle_number ?? '—');
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $company }} · {{ __('transport.page.title') }} · {{ $plate }}</title>
    @include('partials.favicon')
    <style>
        :root { --p: #0f766e; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif; background: #f3f4f6; color: #111827; -webkit-text-size-adjust: 100%; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 0 0 40px; }
        header { background: var(--p); color: #fff; padding: 18px 20px 16px; }
        header h1 { font-size: 17px; letter-spacing: .3px; }
        header .veh { margin-top: 6px; font-size: 22px; font-weight: 800; }
        header .sub { margin-top: 4px; font-size: 13px; opacity: .9; }
        .card { background: #fff; margin: 14px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 14px 16px; }
        .sec-title { font-size: 12px; font-weight: 700; color: #6b7280; margin-bottom: 6px; }
        .msg { font-size: 16px; font-weight: 700; }
        .addr { font-size: 15px; line-height: 1.5; word-break: break-all; }
        .muted { color: #9ca3af; font-size: 13px; }
        .chip { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; background: #ecfdf5; color: #047857; }
        .banner { padding: 10px 14px; border-radius: 8px; font-size: 14px; margin: 14px 14px 0; }
        .banner.ok { background: #dcfce7; color: #166534; }
        .banner.bad { background: #fee2e2; color: #991b1b; }
        .banner.warn { background: #fef3c7; color: #92400e; }
        .media { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .media a { display: block; }
        .media img { width: 100%; border-radius: 8px; background: #000; display: block; aspect-ratio: 4/3; object-fit: cover; }
        input[type=text], textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; font-size: 15px; font-family: inherit; }
        textarea { min-height: 70px; }
        label { display: block; font-size: 12px; color: #6b7280; margin: 10px 0 4px; }
        .btns { display: flex; gap: 10px; margin-top: 12px; }
        .btn { flex: 1; border: 0; border-radius: 10px; padding: 15px; font-size: 17px; font-weight: 800; color: #fff; cursor: pointer; }
        .btn.ok { background: #16a34a; } .btn.bad { background: #dc2626; }
        .err { color: #b91c1c; font-size: 13px; margin-bottom: 6px; }
        footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 18px; padding: 0 14px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>{{ $company }} · {{ __('transport.page.title') }}</h1>
        <div class="veh">{{ $plate }}</div>
        @if ($listing?->vin)<div class="sub">VIN {{ $listing->vin }}</div>@endif
    </header>

    @if ($req->isDecided())
        <div class="banner {{ $req->isAccepted() ? 'ok' : 'bad' }}">{{ __('transport.page.already', ['result' => $req->statusLabel(), 'who' => $req->decidedRecipient?->displayName() ?? '—', 'at' => $req->decided_at?->timezone(config('app.timezone'))->format('m-d H:i') ?? '—']) }}</div>
    @elseif (! $req->isSent())
        <div class="banner warn">{{ __('transport.page.expired') }}</div>
    @elseif ($readonly)
        <div class="banner warn">{{ __('transport.page.readonly') }}</div>
    @elseif (! $decidable)
        <div class="banner warn">{{ __('transport.page.invalid') }}</div>
    @endif

    <div class="card">
        <div class="msg">{{ $req->message }}</div>
        <div class="muted" style="margin-top:6px">{{ __('transport.page.requested_by', ['name' => $req->requester?->name ?? '—', 'at' => ($req->sent_at ?? $req->created_at)?->timezone(config('app.timezone'))->format('m-d H:i')]) }}</div>
    </div>

    <div class="card">
        <div class="sec-title">{{ __('transport.page.address') }}</div>
        <div class="addr">{{ $req->address ?: __('transport.page.address_none') }}</div>
        @if ($req->stock_location)
            <div class="sec-title" style="margin-top:10px">{{ __('transport.page.destination') }}</div>
            <span class="chip">{{ $req->stock_location }}</span>
        @endif
    </div>

    <div class="card">
        <div class="sec-title">{{ __('transport.page.photos') }}</div>
        @if ($req->files->isEmpty())
            <div class="muted">{{ __('transport.page.photos_none') }}</div>
        @else
            <div class="media">
                @foreach ($req->files as $f)
                    @if ($f->isImage())
                        <a href="{{ $f->shareUrl() }}" target="_blank"><img src="{{ $f->shareUrl() }}" loading="lazy" alt=""></a>
                    @else
                        <a href="{{ $f->shareUrl() }}" target="_blank" class="muted">📄 {{ $f->original_name }}</a>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    @if ($decidable && $decideUrl)
        <div class="card">
            @if ($error)<div class="err">{{ $error }}</div>@endif
            <form method="post" action="{{ $decideUrl }}">
                <label for="name">{{ __('transport.page.name') }}</label>
                <input type="text" id="name" name="name" maxlength="60" autocomplete="name">
                <label for="reason">{{ __('transport.page.reason') }}</label>
                <textarea id="reason" name="reason" maxlength="200" placeholder="{{ __('transport.page.reason_ph') }}"></textarea>
                <div class="btns">
                    <button type="submit" name="action" value="accept" class="btn ok">{{ __('transport.page.accept') }}</button>
                    <button type="submit" name="action" value="reject" class="btn bad">{{ __('transport.page.reject') }}</button>
                </div>
            </form>
        </div>
    @endif

    <footer>{{ __('transport.page.footer') }}</footer>
</div>
</body>
</html>
