<?php

/**
 * 등록 xlsx 검증 — `alimtalk-build-upload.php` 산출물이 읽히고 코드와 글자 단위로 같은지 (car-erp 이식, 2026-11-10).
 *
 * 사용법: php scripts/alimtalk-verify-upload.php <템플릿코드> [--only=헤이맨,싼카]
 *
 * 🚨 핵심 = **본문이 `AlimtalkTemplates` 상수와 글자단위로 같은가**. 승인본과 코드가 한 글자라도 다르면 **발송 시점에만** 반려된다.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\IOFactory;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
$CODE = $args[0] ?? null;
$ONLY = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $ONLY = explode(',', substr($a, 7));
    }
}
if (! $CODE || ! isset(AlimtalkTemplates::TEMPLATES[$CODE])) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-verify-upload.php <템플릿코드>\n");
    exit(1);
}
$t = AlimtalkTemplates::TEMPLATES[$CODE];
$BASE = getenv('ALIMTALK_XLSX_ROOT') ?: 'C:/Users/User/Desktop/알림톡';

$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨', '@heyman_con', 'https://board.heysellcar.com'],
    ['싼카확정알림톡', '싼카', '@site_condition', 'https://board.heymancar.com'],
];
if ($ONLY !== null) {
    $COMPANIES = array_values(array_filter($COMPANIES, fn ($c) => in_array($c[1], $ONLY, true)));
}
$BUTTON = $t['button'][0] ?? null;

$fail = 0;
foreach ($COMPANIES as [$dir, $label, $profile, $domain]) {
    $path = "$BASE/$dir/upload_board_{$label}_{$CODE}_신규.xlsx";
    echo "── $label ──\n";
    if (! is_file($path)) {
        echo "  ❌ 파일 없음: $path\n";
        $fail++;

        continue;
    }

    $sh = IOFactory::createReader('Xlsx')->load($path)->getActiveSheet();
    $get = fn (string $c): string => trim((string) $sh->getCell($c)->getValue());

    $checks = [
        '발신프로필(A6)' => [$get('A6'), $profile],
        '템플릿코드(B6)' => [$get('B6'), $CODE],
        '템플릿명(C6)' => [$get('C6'), $t['name']],
        '메시지유형(D6)' => [$get('D6'), 'BA'],
        '본문(E6)' => [str_replace("\r\n", "\n", $get('E6')), trim($t['body'])],
        '카테고리(I6)' => [$get('I6'), '008002'],
        '강조유형(J6)' => [$get('J6'), '선택안함'],
    ];
    foreach ($checks as $what => [$got, $want]) {
        if ($got === $want) {
            echo "  ✅ $what\n";
        } else {
            echo "  ❌ $what\n     기대: ".str_replace("\n", '\n', $want)."\n     실제: ".str_replace("\n", '\n', $got)."\n";
            $fail++;
        }
    }

    for ($r = 7; $r <= 21; $r++) {
        if ($get("B$r") !== '') {
            echo "  ❌ 예시 {$r}행이 남아 있다: ".$get("B$r")."\n";
            $fail++;
        }
    }
    if ($BUTTON !== null) {
        $link = $domain.$BUTTON['register_path'].'#{url}';
        foreach (['AN6' => '웹링크', 'AO6' => $BUTTON['name'], 'AP6' => $link, 'AQ6' => $link] as $c => $want) {
            if ($get($c) === $want) {
                echo "  ✅ 버튼 $c = $want\n";
            } else {
                echo "  ❌ 버튼 $c 기대 $want / 실제 ".$get($c)."\n";
                $fail++;
            }
        }
    }
    foreach (array_merge($BUTTON !== null ? [] : ['AN6', 'AO6'], ['AT6', 'DZ6', 'ED6']) as $c) {
        if ($get($c) !== '') {
            echo "  ❌ 버튼/링크 칸 $c 이 안 비었다: ".$get($c)."\n";
            $fail++;
        }
    }
    preg_match_all('/#\{([^}]+)\}/u', $t['body'], $m);
    $inBody = array_values(array_unique($m[1]));
    if ($inBody !== array_values($t['vars'])) {
        echo '  ⚠️ 본문 변수 순서/구성이 vars 와 다름: '.implode(',', $inBody).' vs '.implode(',', $t['vars'])."\n";
    }
}

echo $fail === 0 ? "\n✅ 전부 통과 — 그대로 BizM 콘솔에 업로드하면 된다.\n" : "\n❌ 실패 {$fail}건\n";
exit($fail === 0 ? 0 : 1);
