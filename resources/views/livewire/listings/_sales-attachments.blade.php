{{--
    딜러 차량 첨부 **읽기 전용** 보기 — $e = 매물 1건.

    올리는 곳은 `/auction`(구매·경매) 드로어 하나뿐인데, 그 화면은 accepted·won 만 다뤄서
    **연동 B 로 ERP 에 넘어가면(synced) board 어디서도 다시 볼 수 없었다.** 여기가 그 조회처다
    (매입예정 목록은 본인 차를 **전 상태**로 열 수 있는 유일한 화면).

    🚫 **삭제는 여전히 없다** — `won` 이후엔 같은 첨부를 ERP 도 갖고 있고, **삭제는 전파되지 않는다**
       — 여기서 지우면 양쪽이 조용히 갈린다(board 가 더 이상 유일한 권위가 아니다).
    ➕ **추가는 된다**(2026-09-11) — 딜러가 사진을 늦게 주면 ERP 에 넘어간 뒤에도 올릴 수 있어야 한다.
       추가는 board→ERP 로 같이 늘어나서 갈리지 않는다(수신측이 멱등 분기에서도 dedup 보강).
       🚨 그래도 **사진 전용 재전송**이다 — 금액까지 같이 보내면 ERP 판매가가 채워지고
       `sale_date` 가 오늘로 찍혀 채권 독촉 기산점까지 바뀐다(§14-12).
    ⚠️ 서류는 미리보기를 그리지 않는다 — 📄 + 파일명만(표시 최소화, 주소·RRN 마스킹본이라도 눈에 덜 띄게).
    ⚠️ URL 은 모델 accessor(`$p->url()`)로 — 디스크가 로컬/S3 로 갈려 경로를 손으로 조립하면 깨진다.
--}}
@php $atts = $e->salesAttachments; @endphp
<div class="section-title-sm mt-4">{{ __('listings.attach_view.title') }}
    <span class="text-[11px] font-normal text-gray-400">{{ __('listings.attach_view.hint') }}</span>
</div>
@if ($atts->isEmpty())
    <p class="mt-1 text-[12px] text-gray-400">{{ __('listings.attach_view.empty') }}</p>
@else
    <div class="mt-1 grid grid-cols-4 gap-2">
        @foreach ($atts as $p)
            <a href="{{ $p->url() }}" target="_blank" rel="noopener"
                class="relative block overflow-hidden rounded-md border border-gray-200 hover:border-violet-400"
                wire:key="lst-att-{{ $p->id }}" title="{{ $p->original_name }}">
                @if ($p->isDocument())
                    <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                        <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $p->original_name }}</span>
                    </div>
                @else
                    <img src="{{ $p->url() }}" class="aspect-square w-full object-cover" alt="" loading="lazy">
                @endif
            </a>
        @endforeach
    </div>
@endif

@php $syncedAtt = (bool) $e->car_erp_vehicle_id; @endphp
@if ($syncedAtt)
    {{-- ERP 로 넘어간 차만 — 아직 안 넘어간 차는 `/auction` 에서 올린다(거기서 구매확정과 함께 나간다).
         ⚠️ **위 그리드(보기 전용)와 시각적으로 갈라 놓아야 한다** — 한 덩어리로 보이면 점선 칸이
            "위에 있는 첨부 설명"으로 읽힌다(Jin 2026-09-11 실측). 소제목 + 보라 점선으로 분리한다. --}}
    <div class="section-title-sm mt-4 text-[var(--color-primary-text)]">{{ __('listings.attach_add.section') }}</div>
    <label class="flex cursor-pointer flex-col items-center justify-center gap-0.5 rounded-lg border-2 border-dashed border-[var(--color-primary)] bg-[var(--color-primary-soft)] py-4 text-[13px] font-semibold text-[var(--color-primary-text)] hover:bg-[#e2ddf5]">
        <span class="text-lg leading-none">＋</span>
        {{ __('listings.attach_add.dropzone') }}
        <span class="text-[11px] font-normal text-gray-500">{{ __('listings.attach_add.dropzone_sub') }}</span>
        <input type="file" multiple wire:model="eSalesFiles" class="hidden">
    </label>
    <div wire:loading wire:target="eSalesFiles" class="mt-1 text-xs text-gray-400">{{ __('listings.attach.uploading') }}</div>

    @if (count($eSalesFiles))
        <div class="mt-2 grid grid-cols-4 gap-2">
            @foreach ($eSalesFiles as $i => $f)
                <div class="relative overflow-hidden rounded-md border border-gray-200" wire:key="lst-newfile-{{ $i }}">
                    @if ($f->isPreviewable() && str_starts_with((string) $f->getMimeType(), 'image/'))
                        <img src="{{ $f->temporaryUrl() }}" class="aspect-square w-full object-cover" alt="">
                    @else
                        <div class="flex aspect-square w-full flex-col items-center justify-center bg-gray-50 p-1 text-center text-[10px] text-gray-500">
                            <span class="text-lg">📄</span><span class="line-clamp-2 break-all">{{ $f->getClientOriginalName() }}</span>
                        </div>
                    @endif
                    <button type="button" wire:click="removeESalesFile({{ $i }})"
                        class="absolute right-0.5 top-0.5 rounded bg-black/55 px-1 text-[10px] font-semibold text-white hover:bg-red-600">✕</button>
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="addAttachments" wire:loading.attr="disabled" wire:target="addAttachments,eSalesFiles"
            class="btn-primary btn-sm mt-2 w-full justify-center">
            <span wire:loading.remove wire:target="addAttachments">{{ __('listings.attach_add.btn') }}</span>
            <span wire:loading wire:target="addAttachments">{{ __('listings.attach_add.sending') }}</span>
        </button>
    @endif

    @error('eSalesFiles') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @error('eSalesFiles.*') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    <p class="mt-1 text-[11px] text-gray-400">{{ __('listings.attach_add.help', ['max' => config('board.attachment_max')]) }}</p>

    @if ($attachResult !== null)
        @php
            $sent = (int) ($attachResult['sent'] ?? 0);
            $added = $attachResult['added'];   // null = 응답에 숫자가 없음(구버전 수신기)
            $short = $added !== null && $added < $sent;   // 🚨 cap 초과는 failed 로 안 잡힌다 — 장수를 비교해야 안다
        @endphp
        <div class="card-sm mt-2 text-[12px] {{ ($attachResult['pending'] ?? false) || $short ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-green-200 bg-green-50 text-green-800' }}">
            @if ($attachResult['pending'] ?? false)
                {{-- 운영 큐는 비동기라 응답이 늦게 온다 — 카드가 스스로 결과를 받아온다(드로어를 다시 열면
                     결과가 초기화되므로 "나중에 다시 열어 보라"고 말할 수 없다). --}}
                <b wire:poll.3s="refreshSyncResult">{{ __('listings.attach_add.queued', ['count' => $sent]) }}</b>
            @elseif ($added === null)
                <b>{{ __('listings.attach_add.sent', ['count' => $sent]) }}</b>
            @elseif ($short)
                <b>{{ __('listings.attach_add.partial', ['sent' => $sent, 'added' => $added]) }}</b>
            @else
                <b>{{ __('listings.attach_add.ok', ['added' => $added]) }}</b>
            @endif
            @if (! empty($attachResult['failed']))
                <div class="mt-1 text-[11px]">{{ __('listings.attach_add.failed', ['count' => $attachResult['failed']]) }}</div>
            @endif
        </div>
    @endif
@endif
