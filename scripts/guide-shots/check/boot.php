<?php

// 씬 점검 공용 부트스트랩 — Laravel 을 띄우고 **로컬·board DB 가 아니면 멈춘다**.
// ⚠️ 이 폴더는 master 로도 가지만(scripts/guide-shots), 운영 박스에서 실행되면 여기서 끝난다.

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('local') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'board') {
    fwrite(STDERR, "로컬 board DB 에서만 돕니다 (env=".app()->environment().', db='.Illuminate\Support\Facades\DB::connection()->getDatabaseName().")\n");
    exit(2);
}
