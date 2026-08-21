{{-- 브라우저 탭 아이콘 — 인스턴스(회사)별 (jin 2026-08-21).
     car-erp 와 **같은 아이콘**을 쓴다(heymanboard=파란 H / ssancarboard=빨간 SS).

     ⚠️ 선언이 없으면 브라우저는 `/favicon.ico` 관례에만 의존한다. 그 파일은 첫 커밋(스타터킷)부터
        **0바이트**라 지금까지 board 는 아이콘이 없었다(크롬이 캐시한 옛 아이콘을 계속 그려서
        「있는 것처럼」 보였을 뿐 — car-erp 에서 실측된 현상이다). 게다가 관례는 파일명이 고정이라
        인스턴스별 분기 자체가 불가능하다.
     ⚠️ `?v=` 는 캐시 무효화용. 파비콘 캐시는 `Ctrl+Shift+R` 로도 잘 안 지워진다. 아이콘을 바꾸면 올릴 것.
     🚨 로그인·에러 페이지를 포함한 **모든 화면**에서 렌더된다 — 여기서 예외가 나면 화면이 통째로
        죽는다. 그래서 DB(Setting) 를 안 보고 config 배열 조회로만 끝낸다.

     지도 = `config/board.php` 의 `favicons`(키 = 그 박스 .env 의 APP_NAME).
     없는 키(로컬 `board`, 아직 없는 karababoard)면 **아무것도 안 그린다** — 아무거나 폴백하면
     그 박스에 다른 회사 로고가 뜬다. --}}
@php($boardFavicon = config('board.favicons')[strtolower(trim((string) config('app.name')))] ?? null)
@if ($boardFavicon)
    <link rel="icon" href="{{ asset($boardFavicon) }}?v=1" sizes="any" />
@endif
