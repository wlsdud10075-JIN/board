<?php

namespace App\Support;

/**
 * 현재 인스턴스(회사)의 화면 자산 — 탭 파비콘·홈 화면(PWA) 아이콘·manifest.
 *
 * 🧭 board 엔 car-erp 의 `company.template_set` 같은 「내가 어느 회사인가」 값이 없다.
 *    대신 APP_NAME 이 이미 박스마다 다르다(실측: `board-heyman` / `board-ssancar`) — 그걸 쓴다.
 *    새 식별 수단을 만들면 두 LIVE 박스의 `.env` 를 건드려야 하고, 그 순간 백업 동기화와
 *    `config:cache`(ubuntu 전용) 가 딸려온다.
 *
 * 🚨 이걸 읽는 partial 은 **로그인·에러 페이지 포함 모든 화면**에서 렌더된다 — 여기서 예외가 나면
 *    화면이 통째로 죽는다. 그래서 DB 를 안 보고 config 배열 조회로만 끝낸다.
 */
class Instance
{
    /** 알 수 없는 APP_NAME(로컬 `board`, 아직 없는 karababoard)이면 null — 호출부는 아무것도 안 그린다. */
    public static function assets(): ?array
    {
        $key = strtolower(trim((string) config('app.name')));

        return config('board.instances')[$key] ?? null;
    }
}
