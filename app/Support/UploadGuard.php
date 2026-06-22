<?php

namespace App\Support;

/**
 * 업로드 파일 안전 가드 — 실행파일 차단(Jin: "exe 같은 실행파일만 차단, 나머지 허용").
 * Livewire temp-upload mimes allowlist(=1차 게이트)와 별개의 확장자 blocklist(=2차 게이트).
 */
class UploadGuard
{
    /** 파일명 확장자가 금지 목록(config('board.blocked_upload_ext'))에 있으면 true. */
    public static function isExecutable(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }

        $blocked = array_map('strtolower', config('board.blocked_upload_ext', []));

        return in_array($ext, $blocked, true);
    }
}
