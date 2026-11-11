{{-- 클레임 승인/반려 결과(또는 오류) — 결정 후 재진입 시에도 이 화면(상태만). --}}
@php
    $ok = $result === 'approve';
    $bad = $result === 'reject';
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('claims.ceo_title') }} · {{ $claim->vehicle_number }}</title>
    @include('partials.favicon')
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif; background: #f3f4f6; color: #111827; margin: 0; }
        .box { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 28px 22px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .big { font-size: 40px; margin-bottom: 10px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        p { color: #6b7280; font-size: 14px; margin: 4px 0; }
    </style>
</head>
<body>
<div class="box">
    <div class="big">{{ $ok ? '✅' : ($bad ? '⛔' : '⚠️') }}</div>
    <h1>{{ $ok ? __('claims.ceo_done_approved') : ($bad ? __('claims.ceo_done_rejected') : __('claims.ceo_done_error')) }}</h1>
    <p>{{ \App\Support\Plate::show($claim->vehicle_number) }} · {{ $claim->buyer_name ?: '—' }}</p>
    @if ($message)<p>{{ $message }}</p>@endif
    @if ($claim->decided_at)<p>{{ $claim->decided_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>@endif
    @if ($ok || $bad)<p>{{ __('claims.ceo_done_hint') }}</p>@endif
</div>
</body>
</html>
