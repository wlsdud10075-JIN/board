{{--
    딜러 차량 첨부 **읽기 전용** 보기 — $e = 매물 1건.

    올리는 곳은 `/auction`(구매·경매) 드로어 하나뿐인데, 그 화면은 accepted·won 만 다뤄서
    **연동 B 로 ERP 에 넘어가면(synced) board 어디서도 다시 볼 수 없었다.** 여기가 그 조회처다
    (매입예정 목록은 본인 차를 **전 상태**로 열 수 있는 유일한 화면).

    🚫 **삭제·업로드 없음** — 편집은 `/auction` 한 곳에서만. `won` 이후엔 같은 첨부를 ERP 도 갖고 있어
       여기서 지우면 양쪽이 조용히 갈린다(board 가 더 이상 유일한 권위가 아니다).
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
