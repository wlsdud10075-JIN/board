{{-- 홈 화면 앱(PWA) 선언 — 인스턴스(회사)별 (jin 2026-08-21).
     설치는 스토어가 아니라 사용자가 폰에서 직접 한다:
       안드로이드 크롬 = ⋮ → 앱 설치 / 아이폰 **사파리** = 공유 → 홈 화면에 추가.
     그러면 주소창 없는 전체화면(standalone)으로 열리고 앱 전환기에도 독립 앱으로 뜬다.

     ⚠️ 아이폰은 manifest 의 icons 를 **안 본다** — `apple-touch-icon` 을 따로 줘야 아이콘이 뜬다.
     ⚠️ 이 선언은 **사내 화면 전용**이다. 바이어 공개 페이지에는 넣지 않는다(바이어가 우리 업무
        보드를 앱으로 설치할 일이 없고, scope 가 `/` 라 설치되면 로그인 화면으로 데려간다).
     ⚠️ manifest 가 `.json` 인 이유 = nginx 기본 mime.types 에 `.webmanifest` 가 없어서다
        (`config/board.php` 주석 참조). 두 박스 nginx 를 안 건드리려는 선택.
     🚨 로그인·에러 페이지 포함 모든 사내 화면에서 렌더된다 — DB 를 안 보고 config 조회로만 끝낸다. --}}
@php($boardInstance = \App\Support\Instance::assets())
@if ($boardInstance)
    <link rel="manifest" href="{{ asset($boardInstance['manifest']) }}" />
    <link rel="apple-touch-icon" href="{{ asset($boardInstance['apple_icon']) }}" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="apple-mobile-web-app-title" content="{{ $boardInstance['title'] }}" />
    <meta name="theme-color" content="#ffffff" />
@endif
