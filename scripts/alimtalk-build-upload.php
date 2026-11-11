<?php

/**
 * BizM 템플릿 등록 xlsx 생성기 — board 판 (car-erp `scripts/alimtalk-build-upload.php` 이식, 2026-11-10 클레임 2종).
 *
 * 사용법:  php scripts/alimtalk-build-upload.php <템플릿코드> <카테고리코드> [--only=헤이맨,싼카]
 *          php scripts/alimtalk-build-upload.php board_claim_approval_request 008002
 *
 * 🏷️ 카테고리는 **필수** — 직원에게 가는 내부 알림 = 008002(업무알림 › 내부 업무 알림). board 템플릿은 전부 사내용.
 * 🔘 버튼 1개(웹링크) — `TEMPLATES[코드]['button'][0]['register_path']` 가 있으면 6행 AN~AQ 에 `{board 도메인}{register_path}#{url}`.
 *    도메인 = 그 인스턴스 board 주소(heymanboard `board.heysellcar.com` · ssancarboard `board.heymancar.com`).
 *    발송 코드(Claim::approvalLinkFor → APP_URL + /a/claim/{id}?…)와 접두가 글자 단위로 같아야 K108 이 안 난다.
 *
 * 출력:    Desktop\알림톡\{회사}확정알림톡\upload_board_{회사}_{코드}_신규.xlsx  (프로필 2개 = 검수 2건/코드)
 *
 * 🚨 **왜 zip 을 손으로 패치하는가**(ERP 2026-08-11 두 번 실패 후 확립): PhpSpreadsheet/openpyxl 로 저장하면 컨테이너가
 *    재작성돼 BizM 업로더가 「양식이 다릅니다」로 거부한다. 베이스 = BizM 공식 샘플(`upload_sample_v2.xlsx`), 엔트리를 바이트 그대로
 *    두고 `xl/worksheets/sheet1.xml` + `xl/sharedStrings.xml` 두 개만 고친다.
 * ⚠️ 문구는 `AlimtalkTemplates` 상수에서 읽는다 — 손으로 옮기면 띄어쓰기가 어긋나 발송이 반려된다.
 */
require __DIR__.'/../vendor/autoload.php';

use App\Support\AlimtalkTemplates;
use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => ! str_starts_with($a, '--')));
$CODE = $args[0] ?? null;
$CATEGORY = $args[1] ?? null;
$ONLY = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $ONLY = explode(',', substr($a, 7));
    }
}

if (! $CODE || ! isset(AlimtalkTemplates::TEMPLATES[$CODE]) || ! preg_match('/^\d{6}$/', (string) $CATEGORY)) {
    fwrite(STDERR, "사용법: php scripts/alimtalk-build-upload.php <템플릿코드> <카테고리코드>\n");
    fwrite(STDERR, "카테고리는 필수 — 직원 내부 알림 008002 (빠뜨리면 조용히 틀린 값이 들어가 반려된다)\n");
    fwrite(STDERR, '알 수 있는 코드: '.implode(', ', array_keys(AlimtalkTemplates::TEMPLATES))."\n");
    exit(1);
}

$t = AlimtalkTemplates::TEMPLATES[$CODE];
$BASE = getenv('ALIMTALK_XLSX_ROOT') ?: 'C:/Users/User/Desktop/알림톡';
$SAMPLE = $BASE.'/upload_sample_v2.xlsx';

/** 회사별 발신프로필(기존 board 등록본 A6 실측 2026-11-10 — ERP 와 같은 채널) + board 도메인. 인스턴스를 늘리면 여기 한 줄. */
$COMPANIES = [
    ['헤이맨확정알림톡', '헤이맨', '@heyman_con', 'https://board.heysellcar.com'],
    ['싼카확정알림톡', '싼카', '@site_condition', 'https://board.heymancar.com'],
];
if ($ONLY !== null) {
    $COMPANIES = array_values(array_filter($COMPANIES, fn ($c) => in_array($c[1], $ONLY, true)));
}
$BUTTON = $t['button'][0] ?? null;
if ($BUTTON !== null && ! isset($BUTTON['register_path'])) {
    fwrite(STDERR, "❌ {$CODE} 버튼에 register_path 가 없다 — 등록 URL 을 만들 수 없다\n");
    exit(1);
}

/** 6행에 넣을 값. null = 그 칸을 **비운다**(샘플 예시의 버튼·미리보기 잔재 제거). */
$vals = [
    'A' => null,          // 회사별로 아래에서 채운다
    'B' => $CODE,
    'C' => $t['name'],
    'E' => $t['body'],
    'I' => $CATEGORY,
    'AN' => null, 'AO' => null, 'AP' => null, 'AQ' => null, 'AT' => null, 'AU' => null,
    'AZ' => null, 'BA' => null, 'BF' => null, 'BG' => null,
    'BL' => null, 'BM' => null, 'DZ' => null, 'ED' => null,
];
// D(메시지유형 BA)·H(보안 FALSE)·J(강조 선택안함)은 샘플 값 그대로 쓴다.

$xmlEscape = fn (string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);

foreach ($COMPANIES as [$dir, $label, $profile, $domain]) {
    $vals['A'] = $profile;
    if ($BUTTON !== null) {
        $link = $domain.$BUTTON['register_path'].'#{url}';
        $vals['AN'] = '웹링크';
        $vals['AO'] = $BUTTON['name'];
        $vals['AP'] = $link;
        $vals['AQ'] = $link;
    }
    if (! is_dir("$BASE/$dir")) {
        mkdir("$BASE/$dir", 0777, true);
    }
    $outPath = "$BASE/$dir/upload_board_{$label}_{$CODE}_신규.xlsx";

    $zin = new ZipArchive;
    if ($zin->open($SAMPLE) !== true) {
        fwrite(STDERR, "❌ 공식 샘플을 못 연다: $SAMPLE\n");
        exit(1);
    }

    $sheet = $zin->getFromName('xl/worksheets/sheet1.xml');
    $strings = $zin->getFromName('xl/sharedStrings.xml');
    if ($sheet === false || $strings === false) {
        fwrite(STDERR, "❌ sheet1.xml / sharedStrings.xml 이 없다 — 샘플이 바뀌었는지 확인할 것\n");
        exit(1);
    }

    // ── ① 새 문자열을 sharedStrings 끝에 붙이고 인덱스를 받는다 ──────────────
    $siCount = preg_match_all('/<si>/', $strings);
    $append = '';
    $index = [];
    foreach ($vals as $col => $value) {
        if ($value === null) {
            continue;
        }
        $index[$col] = $siCount + count($index);
        $append .= '<si><t xml:space="preserve">'.$xmlEscape($value).'</t></si>';
    }
    $strings = str_replace('</sst>', $append.'</sst>', $strings);

    // ── ② 6행 셀 교체 ────────────────────────────────────────────────────
    if (! preg_match('/<row r="6"[^>]*>.*?<\/row>/s', $sheet, $m)) {
        fwrite(STDERR, "❌ 6행을 못 찾았다\n");
        exit(1);
    }
    $row6 = $m[0];
    $newRow6 = $row6;
    foreach ($vals as $col => $value) {
        $re = '/<c r="'.$col.'6"([^>]*?)(\/>|>.*?<\/c>)/s';
        if (! preg_match($re, $newRow6, $cm)) {
            fwrite(STDERR, "❌ 셀 {$col}6 이 없다 — 샘플 열 배치가 바뀌었는지 4·5행을 대조할 것\n");
            exit(1);
        }
        preg_match('/\ss="\d+"/', $cm[1], $sm);   // 스타일은 보존
        $style = $sm[0] ?? '';
        $newRow6 = str_replace($cm[0], $value === null
            ? '<c r="'.$col.'6"'.$style.'/>'
            : '<c r="'.$col.'6"'.$style.' t="s"><v>'.$index[$col].'</v></c>', $newRow6);
    }
    $sheet = str_replace($row6, $newRow6, $sheet);

    // ── ③ 예시 15행(7~21) 제거 + dimension 축소 ──────────────────────────
    for ($r = 7; $r <= 21; $r++) {
        $sheet = preg_replace('/<row r="'.$r.'"[^>]*>.*?<\/row>/s', '', $sheet, 1);
    }
    $sheet = preg_replace('/<dimension ref="A1:[A-Z]+\d+"\/>/', '<dimension ref="A1:ED6"/>', $sheet, 1);

    // ── ④ sst count/uniqueCount 재계산 ───────────────────────────────────
    $tsCount = preg_match_all('/ t="s"/', $sheet);
    $siTotal = preg_match_all('/<si>/', $strings);
    $strings = preg_replace('/<sst([^>]*?)count="\d+" uniqueCount="\d+"/',
        '<sst$1count="'.$tsCount.'" uniqueCount="'.$siTotal.'"', $strings, 1);

    // ── ⑤ 나머지 엔트리는 **바이트 그대로** 복사 ──────────────────────────
    // 🚨 덮어쓰기 전 자동 백업(ERP 2026-09-07 사고 — 심사 제출본을 다른 템플릿으로 덮어썼다).
    if (is_file($outPath)) {
        $bak = $outPath.'.bak-'.date('YmdHi');
        if (! is_file($bak) && ! @copy($outPath, $bak)) {
            fwrite(STDERR, "❌ 기존 파일 백업 실패 — 중단: $outPath\n");
            exit(1);
        }
        echo '   ↳ 기존 파일 백업: '.basename($bak)."\n";
    }
    @unlink($outPath);
    $zout = new ZipArchive;
    if ($zout->open($outPath, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "❌ 출력 파일을 못 만든다: $outPath\n");
        exit(1);
    }
    $touched = 0;
    for ($i = 0; $i < $zin->numFiles; $i++) {
        $name = $zin->getNameIndex($i);
        if ($name === 'xl/worksheets/sheet1.xml') {
            $zout->addFromString($name, $sheet);
            $touched++;
        } elseif ($name === 'xl/sharedStrings.xml') {
            $zout->addFromString($name, $strings);
            $touched++;
        } else {
            $zout->addFromString($name, $zin->getFromIndex($i));
        }
    }
    $zout->close();
    $zin->close();

    if ($touched !== 2) {
        fwrite(STDERR, "❌ 고친 엔트리가 2개가 아니다($touched) — 중단\n");
        exit(1);
    }
    echo "✅ $outPath\n";
}

echo "\n검증: php scripts/alimtalk-verify-upload.php $CODE".($ONLY ? ' --only='.implode(',', $ONLY) : '')."\n";
