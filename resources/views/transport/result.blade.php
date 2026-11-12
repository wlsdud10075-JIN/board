@php $plate = \App\Support\Plate::show($req->listing?->vehicle_number ?? '—'); @endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('transport.page.title') }} · {{ $plate }}</title>
    @include('partials.favicon')
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif; background: #f3f4f6; color: #111827; margin: 0; }
        .box { max-width: 520px; margin: 40px auto; background: #fff; border-radius: 12px; padding: 28px 22px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .veh { font-size: 20px; font-weight: 800; margin-bottom: 10px; }
        .ok { color: #166534; font-size: 18px; font-weight: 700; }
        .bad { color: #991b1b; font-size: 18px; font-weight: 700; }
        .err { color: #92400e; font-size: 16px; font-weight: 700; }
        .muted { color: #6b7280; font-size: 13px; margin-top: 10px; }
    </style>
</head>
<body>
<div class="box">
    <div class="veh">{{ $plate }}</div>
    @if ($result === 'accept')
        <div class="ok">✅ {{ __('transport.page.done_accept') }}</div>
    @elseif ($result === 'reject')
        <div class="bad">{{ __('transport.page.done_reject') }}</div>
    @else
        <div class="err">{{ $message }}</div>
    @endif
    <div class="muted">{{ __('transport.page.footer') }}</div>
</div>
</body>
</html>
