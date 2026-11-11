{{--
    클레임 대표 승인 화면 — 알림톡 버튼(서명 링크)에서 열린다. 로그인 없음·폰 폭 기준(ERP a/payout 과 같은 결).
    위 = 우리 검차 미디어 / 바이어 증빙 비교 → 보고서(양식) → 승인/반려(반려 사유 필수). GET 은 표시만, 결정은 60분 서명 POST.
--}}
@php
    $kinds = $claim->files->groupBy('kind');
    $status = $claim->status;
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $company }} · {{ __('claims.ceo_title') }} · {{ $claim->vehicle_number }}</title>
    @include('partials.favicon')
    <style>
        :root { --p: #7c6fcd; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif; background: #f3f4f6; color: #111827; -webkit-text-size-adjust: 100%; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 0 0 40px; }
        header { background: var(--p); color: #fff; padding: 18px 20px 16px; }
        header h1 { font-size: 18px; letter-spacing: .3px; }
        header .veh { margin-top: 6px; font-size: 15px; opacity: .92; }
        .card { background: #fff; margin: 14px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 14px 16px; }
        .sec-title { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 8px; }
        .banner { padding: 10px 14px; border-radius: 8px; font-size: 14px; margin: 14px 14px 0; }
        .banner.ok { background: #dcfce7; color: #166534; }
        .banner.bad { background: #fee2e2; color: #991b1b; }
        .banner.warn { background: #fef3c7; color: #92400e; }
        .cmp { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .cmp h4 { font-size: 12px; color: #6b7280; margin-bottom: 6px; }
        .media { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .media img, .media video { width: 100%; border-radius: 6px; background: #000; display: block; aspect-ratio: 4/3; object-fit: cover; }
        .video-embed { position: relative; margin: 0 0 8px; padding-bottom: 56.25%; height: 0; border-radius: 8px; overflow: hidden; background: #000; }
        .video-embed iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        .doc { display: block; font-size: 12px; color: var(--p); margin: 4px 0; word-break: break-all; }
        .empty { color: #9ca3af; font-size: 13px; padding: 8px 0; }
        .kind { font-size: 11px; color: #6b7280; margin: 6px 0 3px; }
        .rep { font-size: 13px; }
        .rep-title { font-size: 16px; font-weight: 800; text-align: center; letter-spacing: 1px; margin: 4px 0 10px; }
        .rep-title-2 { margin-top: 22px; padding-top: 14px; border-top: 2px dashed #e5e7eb; }
        .rep-h { font-size: 12px; font-weight: 700; color: #374151; background: #f3f4f6; padding: 5px 8px; margin: 12px 0 4px; border-left: 3px solid var(--p); }
        .rep-t { width: 100%; border-collapse: collapse; }
        .rep-t th, .rep-t td { border: 1px solid #e5e7eb; padding: 5px 7px; vertical-align: top; text-align: left; }
        .rep-t th { width: 22%; background: #fafafa; font-size: 11px; color: #6b7280; font-weight: 600; white-space: nowrap; }
        .rep-list th { width: auto; }
        .rep-num { text-align: right; white-space: nowrap; }
        .rep-pre { white-space: pre-wrap; word-break: break-word; }
        .rep-muted { color: #9ca3af; font-size: 11px; }
        .rep-bad { color: #b91c1c; font-weight: 700; font-size: 11px; margin-left: 4px; }
        .rep-chk { display: inline-block; margin-right: 10px; white-space: nowrap; }
        .rep-p { margin: 6px 0; }
        textarea { width: 100%; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; font-size: 14px; min-height: 80px; font-family: inherit; }
        .btns { display: flex; gap: 10px; margin-top: 10px; }
        .btn { flex: 1; border: 0; border-radius: 10px; padding: 14px; font-size: 16px; font-weight: 700; color: #fff; cursor: pointer; }
        .btn.ok { background: #16a34a; } .btn.bad { background: #dc2626; }
        .err { color: #b91c1c; font-size: 13px; margin-bottom: 6px; }
        .hint { font-size: 12px; color: #9ca3af; margin-top: 8px; line-height: 1.5; }
        footer { text-align: center; color: #9ca3af; font-size: 12px; margin-top: 18px; }
        @media (max-width: 480px) { .cmp { grid-template-columns: 1fr; } .rep-t th { width: 30%; white-space: normal; } }
        @media print { .btns, .card.decide, header { display: none; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <h1>{{ $company }} · {{ __('claims.ceo_title') }}</h1>
        <div class="veh">{{ \App\Support\Plate::show($claim->vehicle_number) }} · {{ $claim->buyer_name ?: '—' }} · {{ __('claims.rev_n', ['n' => $claim->revision]) }}</div>
    </header>

    @if ($status === 'approved' || $status === 'rejected' || $status === 'closed')
        <div class="banner {{ $claim->decision === 'approved' ? 'ok' : 'bad' }}">
            {{ __('claims.ceo_already', ['result' => $claim->statusLabel(), 'at' => $claim->decided_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '—']) }}
        </div>
    @elseif (! $decidable)
        @php $cur = $claim->currentStep(); @endphp
        <div class="banner warn">{{ $cur ? __('claims.link_not_your_turn', ['title' => $cur->title, 'name' => $cur->approver?->name ?? '—']) : __('claims.link_not_decidable') }}</div>
    @elseif ($user && $user->isSuper() && (int) $claim->currentStep()?->approver_user_id !== (int) $user->id)
        <div class="banner warn">{{ __('claims.proxy_banner') }}</div>
    @endif
    @if ($claim->lineSteps()->isNotEmpty())
        <div class="card" style="padding:10px 16px;font-size:13px">
            @foreach ($claim->lineSteps() as $s)
                <span style="white-space:nowrap">{{ $s->status === 'approved' ? '✅' : ($s->status === 'rejected' ? '⛔' : ((int) $s->seq === (int) $claim->current_step ? '⏳' : '·')) }} {{ $s->title }} {{ $s->approver?->name ?? '—' }}</span>@if (! $loop->last) <span style="color:#d1d5db">→</span> @endif
            @endforeach
        </div>
    @endif
    @if ($claim->isOverdue())
        <div class="banner warn">⚠️ {{ __('claims.overdue_banner', ['days' => $claim->daysAfterArrival()]) }}</div>
    @endif

    {{-- 미디어 비교 2열 — 우리 검차(board 사진 + ssancar CDN) ↔ 바이어 증빙(영업 업로드) --}}
    <div class="card">
        <div class="sec-title">{{ __('claims.ceo_compare') }}</div>
        <div class="cmp">
            <div>
                <h4>{{ __('claims.ours_media') }}@if ($iu = \App\Models\Claim::ssancarInspectedUrl($claim->vehicle_number)) · <a href="{{ $iu }}" target="_blank" rel="noopener" style="color:var(--p)">{{ __('claims.ssancar_inspected_link') }}</a>@endif</h4>
                @php $ours = ($media['board'] ?? []) !== [] || ($media['videos'] ?? []) !== [] || ($media['photos'] ?? []) !== []; @endphp
                @if ($ours)
                    @foreach ($media['videos'] as $v)
                        @if (! empty($v['embed_url']))
                            <div class="video-embed"><iframe src="{{ $v['embed_url'] }}" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div>
                        @elseif (! empty($v['url']))
                            <video src="{{ $v['url'] }}" controls preload="metadata" style="width:100%;border-radius:8px;background:#000;display:block;margin-bottom:8px;"></video>
                        @endif
                    @endforeach
                    <div class="media">
                        @foreach ($media['board'] as $m)
                            @if ($m['video'])<video src="{{ $m['url'] }}" controls preload="metadata"></video>@else<img src="{{ $m['url'] }}" loading="lazy" alt="">@endif
                        @endforeach
                        @foreach ($media['photos'] as $u)<img src="{{ $u }}" loading="lazy" alt="">@endforeach
                    </div>
                @else
                    <div class="empty">{{ __('claims.inspection_media_none') }}</div>
                @endif
            </div>
            <div>
                <h4>{{ __('claims.buyer_media') }}</h4>
                @forelse (\App\Models\ClaimFile::KINDS as $k)
                    @continue(($kinds[$k] ?? collect())->isEmpty())
                    <div class="kind">{{ __('claims.kind.'.$k) }}</div>
                    <div class="media">
                        @foreach ($kinds[$k] as $file)
                            @if ($file->isVideo())
                                <video src="{{ $file->shareUrl() }}" controls preload="metadata"></video>
                            @elseif ($file->isImage())
                                <a href="{{ $file->shareUrl() }}" target="_blank"><img src="{{ $file->shareUrl() }}" loading="lazy" alt=""></a>
                            @else
                                <a class="doc" href="{{ $file->shareUrl() }}" target="_blank">📄 {{ $file->original_name }}</a>
                            @endif
                        @endforeach
                    </div>
                @empty
                    <div class="empty">—</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="card">
        @include('claim-approval._report', ['claim' => $claim, 'media' => $media])
    </div>

    @if ($decidable)
        <div class="card decide">
            <div class="sec-title">{{ __('claims.ceo_decide') }}</div>
            @if ($error)<p class="err">{{ $error }}</p>@endif
            <form method="post" action="{{ $decideUrl }}" onsubmit="return window.__claimSubmit(this)">
                <textarea name="reason" placeholder="{{ __('claims.ceo_reason_ph') }}">{{ old('reason') }}</textarea>
                <div class="btns">
                    <button class="btn ok" type="submit" name="action" value="approve">{{ __('claims.approve') }}</button>
                    <button class="btn bad" type="submit" name="action" value="reject">{{ __('claims.reject') }}</button>
                </div>
            </form>
            <p class="hint">{{ __('claims.ceo_hint') }}</p>
        </div>
        <script>
            // 더블클릭 2회 전송 방지(서버 lockForUpdate 가 최종 가드) + 반려는 사유 확인.
            window.__claimSubmit = function (form) {
                var btn = document.activeElement;
                if (btn && btn.value === 'reject' && !form.reason.value.trim()) { alert(@json(__('claims.reject_reason_required'))); return false; }
                if (form.__sent) return false; form.__sent = true;
                form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                // disabled 버튼은 값이 안 실린다 → hidden 으로 보존
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'action'; h.value = btn ? btn.value : 'approve'; form.appendChild(h);
                return true;
            };
        </script>
    @endif

    <footer>{{ $company }}</footer>
</div>
</body>
</html>
