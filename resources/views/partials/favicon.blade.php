{{-- 브라우저 탭 아이콘 — 인스턴스(회사)별 (jin 2026-08-21).
     car-erp 와 **같은 아이콘**을 쓴다(heymanboard=파란 H / ssancarboard=빨간 SS).

     ⚠️ 선언이 없으면 브라우저는 `/favicon.ico` 관례에만 의존한다. 그 파일은 첫 커밋(스타터킷)부터
        **0바이트**라 지금까지 board 는 아이콘이 없었다(크롬이 캐시한 옛 아이콘을 계속 그려서
        「있는 것처럼」 보였을 뿐 — car-erp 에서 실측된 현상이다). 게다가 관례는 파일명이 고정이라
        인스턴스별 분기 자체가 불가능하다.
     ⚠️ `?v=` 는 캐시 무효화용. 파비콘 캐시는 `Ctrl+Shift+R` 로도 잘 안 지워진다. 아이콘을 바꾸면 올릴 것.

     지도 = `config/board.php` 의 `instances`(키 = 그 박스 .env 의 APP_NAME). 없는 키면 안 그린다.
     ⚠️ 이 partial 은 **바이어 공개 페이지에서도** 쓴다 — PWA(홈 화면) 선언은 여기 넣지 말 것.
        그건 사내 화면 전용이라 `partials/pwa.blade.php` 에 따로 있다. --}}
@php($boardInstance = \App\Support\Instance::assets())
@if ($boardInstance)
    <link rel="icon" href="{{ asset($boardInstance['favicon']) }}?v=1" sizes="any" />
@endif
