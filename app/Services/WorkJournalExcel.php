<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 업무일지 하루치 → 회사 「해외영업부 업무일지」 양식 엑셀.
 *
 * 양식 원본 = 해외영업부가 쓰던 업무일지.xlsx 의 「작성 예시」 시트 행 배치
 * (빈 양식 시트에는 「금일 계약 차량」 칸이 없다) + 빈 양식 시트의 열 너비.
 * 런타임에 원본 파일을 읽지 않고 코드로 다시 그린다. DB 의존 없음 — 배열만 받는다.
 *
 * $data = [
 *   'date' => 'Y-m-d', 'writer' => string, 'status_label' => string, 'signature' => ?string,
 *   'entries' => [['time' => 'HH:MM', 'location' => ?, 'task' => ?, 'note' => ?], ...],
 *   'contracts' => [['buyer' => ?, 'plate' => ?, 'sale_price' => int|float|string|null, 'currency' => ?], ...],
 *   'contract_totals' => ['count' => int, 'by_currency' => ['EUR' => 14000, ...]],
 *   'plan_tomorrow' => ?string, 'remarks' => ?string,
 * ]
 */
final class WorkJournalExcel
{
    public const SHEET_TITLE = '일일 업무일지';

    public const TITLE = '해외영업부 업무일지';

    public const BRAND = 'SSANCAR';

    /** 양식의 시간 칸 수(09:00~18:00). 이보다 많으면 행을 늘린다. */
    public const MIN_TIME_ROWS = 10;

    public const MIN_CONTRACT_ROWS = 3;

    public const MIN_PLAN_ROWS = 4;

    // 원본 색
    private const NAVY = 'FF142B49';      // 제목·헤더 배경

    private const TEXT = 'FF22354D';      // 본문 글자

    private const LABEL = 'FF67788D';     // 작성일·담당자·서명 라벨

    private const WHITE = 'FFFFFFFF';

    private const INPUT = 'FFF1F5FA';     // 입력칸(작성일·담당자·서명·계약·예정/특이 홀수행)

    private const TIME_BG = 'FFE9EFF7';   // 시간 칸

    private const ZEBRA = 'FFF5F7FB';     // 시간 행 짝수번째

    /** 원본 「일일 업무일지」 열 너비. */
    private const COL_WIDTHS = ['A' => 13, 'B' => 14, 'C' => 17, 'D' => 12.1640625, 'E' => 17, 'F' => 12, 'G' => 12, 'H' => 14];

    public static function build(array $data): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11)->getColor()->setARGB(self::TEXT);
        $book->getDefaultStyle()->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $ws = $book->getActiveSheet();
        $ws->setTitle(self::SHEET_TITLE);
        $ws->setShowGridlines(false);
        $ws->getDefaultRowDimension()->setRowHeight(14);
        foreach (self::COL_WIDTHS as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }

        self::header($ws, $data);
        $r = self::timeRows($ws, $data['entries'] ?? [], 8);

        $ws->getRowDimension($r)->setRowHeight(14);
        $r = self::contracts($ws, $data, $r + 1);

        $ws->getRowDimension($r)->setRowHeight(14);
        $last = self::planAndRemarks($ws, (string) ($data['plan_tomorrow'] ?? ''), (string) ($data['remarks'] ?? ''), $r + 1);

        // 원본처럼 양식 전체 바탕 흰색(격자선 숨김 + 흰 배경)
        self::paintBackground($ws, $last);

        $ws->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setPrintArea('A1:H'.$last);
        $ws->setSelectedCell('A1');

        return $book;
    }

    public static function download(array $data, string $filename): StreamedResponse
    {
        $book = self::build($data);
        $fallback = 'work-journal-'.preg_replace('/[^0-9-]/', '', (string) ($data['date'] ?? '')).'.xlsx';
        $ascii = preg_match('/^[\x20-\x7E]+$/', $filename) && ! str_contains($filename, '%') ? $filename : $fallback;

        return new StreamedResponse(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $ascii),
            'Cache-Control' => 'max-age=0, no-store',
        ]);
    }

    // ── 블록 ────────────────────────────────────────────────────────────

    private static function header(Worksheet $ws, array $data): void
    {
        $ws->getRowDimension(1)->setRowHeight(10);
        $ws->getRowDimension(2)->setRowHeight(34);
        $ws->getRowDimension(3)->setRowHeight(7);
        $ws->getRowDimension(4)->setRowHeight(10);
        $ws->getRowDimension(5)->setRowHeight(29);
        $ws->getRowDimension(6)->setRowHeight(14);
        $ws->getRowDimension(7)->setRowHeight(29);

        $ws->mergeCells('A2:F2');
        self::text($ws, 'A2', self::TITLE);
        $ws->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 26, 'color' => ['argb' => self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $ws->mergeCells('G2:H2');
        self::text($ws, 'G2', self::BRAND);
        $ws->getStyle('G2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $ws->getStyle('A3:H3')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);

        // 작성일 · 담당자 · 서명
        foreach (['A5' => '작성일', 'D5' => '담당자', 'G5' => '서명'] as $cell => $label) {
            self::text($ws, $cell, $label);
            $ws->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => self::LABEL]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
        }
        $ws->mergeCells('B5:C5');
        $ws->mergeCells('E5:F5');
        foreach (['B5:C5', 'E5:F5', 'H5'] as $range) {
            $ws->getStyle($range)->applyFromArray([
                'fill' => self::fill(self::INPUT),
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        $date = self::parseDate((string) ($data['date'] ?? ''));
        if ($date !== null) {
            $ws->setCellValue('B5', ExcelDate::PHPToExcel($date));
            $ws->getStyle('B5')->getNumberFormat()->setFormatCode('yyyy\-mm\-dd');
        } else {
            self::text($ws, 'B5', (string) ($data['date'] ?? ''));
        }
        self::text($ws, 'E5', (string) ($data['writer'] ?? ''));

        // 서명: 손글씨 서명 대신 결재 결과 문구. H5 는 한 칸(14폭)이라 「 · 」에서 줄바꿈 + 작은 글씨.
        $sign = $data['signature'] ?? null;
        $sign = ($sign === null || $sign === '') ? (string) ($data['status_label'] ?? '') : (string) $sign;
        $signLines = $sign === '' ? [] : explode(' · ', $sign);
        self::text($ws, 'H5', implode("\n", $signLines));
        $ws->getStyle('H5')->applyFromArray([
            'font' => ['size' => 8],
            'alignment' => ['wrapText' => true],
        ]);
        if (count($signLines) > 2) {
            $ws->getRowDimension(5)->setRowHeight(max(29, count($signLines) * 11 + 4));
        }

        // 표 헤더
        $ws->mergeCells('C7:E7');
        $ws->mergeCells('F7:H7');
        foreach (['A7' => '시간', 'B7' => '위치', 'C7' => '진행 업무', 'F7' => '비고'] as $cell => $label) {
            self::text($ws, $cell, $label);
        }
        $ws->getStyle('A7:H7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::WHITE]],
            'fill' => self::fill(self::NAVY),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        foreach (['A7', 'B7', 'E7'] as $cell) {
            $ws->getStyle($cell)->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
        }
    }

    /** @return int 다음 빈 행 번호 */
    private static function timeRows(Worksheet $ws, array $entries, int $start): int
    {
        $entries = array_values(array_filter($entries, 'is_array'));
        usort($entries, fn ($a, $b) => self::sortKey($a['time'] ?? null) <=> self::sortKey($b['time'] ?? null));
        $n = max(self::MIN_TIME_ROWS, count($entries));

        for ($i = 0; $i < $n; $i++) {
            $r = $start + $i;
            $e = $entries[$i] ?? [];
            $ws->mergeCells("C{$r}:E{$r}");
            $ws->mergeCells("F{$r}:H{$r}");

            $bg = $i % 2 === 0 ? self::WHITE : self::ZEBRA;
            $ws->getStyle("B{$r}:H{$r}")->applyFromArray([
                'fill' => self::fill($bg),
                'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_LEFT],
            ]);
            $ws->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("A{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => self::NAVY]],
                'fill' => self::fill(self::TIME_BG),
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            ]);
            $ws->getStyle("A{$r}:H{$r}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
            $ws->getStyle("A{$r}")->getNumberFormat()->setFormatCode('h:mm');

            $time = self::parseTime($e['time'] ?? null);
            if ($time !== null) {
                $ws->setCellValue("A{$r}", $time);
            } elseif (($e['time'] ?? '') !== '') {
                self::text($ws, "A{$r}", (string) $e['time']);
            }
            $loc = (string) ($e['location'] ?? '');
            $task = (string) ($e['task'] ?? '');
            $note = (string) ($e['note'] ?? '');
            self::text($ws, "B{$r}", $loc);
            self::text($ws, "C{$r}", $task);
            self::text($ws, "F{$r}", $note);

            // 병합 칸은 엑셀이 행 높이를 자동으로 안 맞춘다 → 줄 수로 계산(원본 42pt = 2줄)
            $lines = max(self::lineCount($loc, 14), self::lineCount($task, 46), self::lineCount($note, 38));
            $ws->getRowDimension($r)->setRowHeight(max(42, $lines * 15 + 8));
        }

        return $start + $n;
    }

    /** @return int 다음 빈 행 번호 */
    private static function contracts(Worksheet $ws, array $data, int $r): int
    {
        $ws->getRowDimension($r)->setRowHeight(27);
        $ws->mergeCells("A{$r}:H{$r}");
        self::text($ws, "A{$r}", '금일 계약 차량');
        $ws->getStyle("A{$r}:H{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::WHITE]],
            'fill' => self::fill(self::NAVY),
        ]);

        $lines = self::contractLines($data['contracts'] ?? [], $data['contract_totals'] ?? null);
        $rows = max(self::MIN_CONTRACT_ROWS, count($lines));
        $top = $r + 1;
        $bottom = $top + $rows - 1;
        $ws->mergeCells("A{$top}:H{$bottom}");
        self::text($ws, "A{$top}", implode("\n", $lines));
        $ws->getStyle("A{$top}:H{$bottom}")->applyFromArray([
            'fill' => self::fill(self::INPUT),
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
        ]);
        $ws->getStyle("A{$bottom}:H{$bottom}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        // 원본 = 24pt × 3행에 3줄. 줄 수가 행 수를 넘을 일은 없지만(행=줄) 긴 줄 감김 대비 여유.
        $wrapped = 0;
        foreach ($lines as $line) {
            $wrapped += self::lineCount($line, 100);
        }
        $per = max(24, (int) ceil(($wrapped * 16 + 8) / $rows));
        for ($i = $top; $i <= $bottom; $i++) {
            $ws->getRowDimension($i)->setRowHeight($per);
        }

        return $bottom + 1;
    }

    /** @return int 마지막 행 번호 */
    private static function planAndRemarks(Worksheet $ws, string $plan, string $remarks, int $r): int
    {
        $ws->getRowDimension($r)->setRowHeight(27);
        $ws->mergeCells("A{$r}:D{$r}");
        $ws->mergeCells("E{$r}:H{$r}");
        self::text($ws, "A{$r}", '내일 예정업무');
        self::text($ws, "E{$r}", '특이사항');
        $ws->getStyle("A{$r}:H{$r}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => self::WHITE]],
            'fill' => self::fill(self::NAVY),
        ]);
        $ws->getStyle("D{$r}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM);

        $planLines = self::splitLines($plan);
        $remarkLines = self::splitLines($remarks);
        $n = max(self::MIN_PLAN_ROWS, count($planLines), count($remarkLines));

        for ($i = 0; $i < $n; $i++) {
            $row = $r + 1 + $i;
            $ws->mergeCells("A{$row}:D{$row}");
            $ws->mergeCells("E{$row}:H{$row}");
            $ws->getStyle("A{$row}:H{$row}")->applyFromArray([
                'fill' => self::fill($i % 2 === 0 ? self::INPUT : self::WHITE),
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
                'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $ws->getStyle("D{$row}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);

            $p = $planLines[$i] ?? '';
            $m = $remarkLines[$i] ?? '';
            self::text($ws, "A{$row}", $p);
            self::text($ws, "E{$row}", $m);
            $lines = max(self::lineCount($p, 56), self::lineCount($m, 55));
            $ws->getRowDimension($row)->setRowHeight(max(30, $lines * 15 + 8));
        }

        return $r + $n;
    }

    // ── 문구 ────────────────────────────────────────────────────────────

    /** 「바이어: 차량번호 / 판매금액 2,000 EUR」 n줄 + 「총계약 N대 / 총판매금액 …」 */
    public static function contractLines(array $contracts, ?array $totals = null): array
    {
        $contracts = array_values(array_filter($contracts, 'is_array'));
        if ($contracts === []) {
            return ['금일 계약 없음'];
        }

        $lines = [];
        $sum = [];
        foreach ($contracts as $c) {
            $buyer = trim((string) ($c['buyer'] ?? '')) ?: '바이어 미정';
            $plate = trim((string) ($c['plate'] ?? '')) ?: '—';
            $cur = strtoupper(trim((string) ($c['currency'] ?? '')));
            $price = $c['sale_price'] ?? null;
            $amount = is_numeric($price) ? self::money((float) $price).($cur !== '' ? ' '.$cur : '') : '미입력';
            $lines[] = "{$buyer}: {$plate} / 판매금액 {$amount}";
            if (is_numeric($price) && $cur !== '') {
                $sum[$cur] = ($sum[$cur] ?? 0) + (float) $price;
            }
        }

        $count = (int) ($totals['count'] ?? count($contracts));
        $by = is_array($totals['by_currency'] ?? null) ? $totals['by_currency'] : $sum;
        $parts = [];
        foreach ($by as $cur => $amt) {
            $parts[] = self::money((float) $amt).' '.$cur;
        }
        $lines[] = "총계약 {$count}대 / 총판매금액 ".($parts === [] ? '—' : implode(' · ', $parts));

        return $lines;
    }

    // ── 도우미 ──────────────────────────────────────────────────────────

    /** 자유 문구는 전부 문자열로 박는다(「=」로 시작하면 수식, 숫자 같으면 숫자로 바뀌는 것 방지). */
    private static function text(Worksheet $ws, string $cell, string $value): void
    {
        if ($value === '') {
            return;
        }
        $ws->getCell($cell)->setValueExplicit(str_replace(["\r\n", "\r"], "\n", $value), DataType::TYPE_STRING);
    }

    private static function fill(string $argb): array
    {
        return ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $argb], 'endColor' => ['argb' => $argb]];
    }

    private static function paintBackground(Worksheet $ws, int $last): void
    {
        // 이미 색을 칠한 칸은 건드리지 않고, 나머지(여백 행·라벨 칸)만 흰색.
        for ($r = 1; $r <= $last; $r++) {
            foreach (range('A', 'H') as $c) {
                $f = $ws->getStyle("{$c}{$r}")->getFill();
                if ($f->getFillType() === Fill::FILL_NONE || $f->getFillType() === null) {
                    $ws->getStyle("{$c}{$r}")->applyFromArray(['fill' => self::fill(self::WHITE)]);
                }
            }
        }
    }

    private static function splitLines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        return $text === '' ? [] : array_values(array_map('rtrim', explode("\n", $text)));
    }

    /** 칸 폭(문자 수, 한글 = 2)으로 감기는 줄 수까지 센 대략값. */
    private static function lineCount(string $text, int $widthChars): int
    {
        if ($text === '') {
            return 1;
        }
        $n = 0;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $seg) {
            $n += max(1, (int) ceil(mb_strwidth($seg, 'UTF-8') / max(1, $widthChars)));
        }

        return $n;
    }

    private static function money(float $v): string
    {
        $dec = abs($v - round($v)) < 0.005 ? 0 : 2;

        return number_format($v, $dec);
    }

    private static function parseTime(mixed $time): ?float
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($time), $m)) {
            return null;
        }
        [$h, $i] = [(int) $m[1], (int) $m[2]];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return ($h * 60 + $i) / 1440;
    }

    /** 시간 순 정렬 키 — 시간 없는 행은 맨 뒤. */
    private static function sortKey(mixed $time): int
    {
        $t = self::parseTime(is_string($time) ? $time : null);

        return $t === null ? PHP_INT_MAX : (int) round($t * 1440);
    }

    private static function parseDate(string $date): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        return $d && $d->format('Y-m-d') === $date ? $d : null;
    }
}
