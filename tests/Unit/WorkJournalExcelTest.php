<?php

namespace Tests\Unit;

use App\Services\WorkJournalExcel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/** 업무일지 엑셀 = 해외영업부 양식 배치. DB·Laravel 부팅 불필요. */
class WorkJournalExcelTest extends TestCase
{
    private function data(array $override = []): array
    {
        return array_merge([
            'date' => '2026-11-16', 'writer' => '김영업', 'status_label' => '결재 완료',
            'signature' => '최종 승인 · 대표 홍대표 · 11-16 18:20',
            'entries' => [
                ['time' => '10:00', 'location' => '화물단지', 'task' => '바이어 요청 차량 사진 촬영', 'note' => '11가1001'],
                ['time' => '09:00', 'location' => '사무실', 'task' => '바이어 요청 차량 가격 문의', 'note' => "11가1001\n11가1002"],
            ],
            'contracts' => [
                ['buyer' => 'GUIDE SAMPLE TRADING', 'plate' => '11가1001', 'sale_price' => 2000, 'currency' => 'EUR'],
                ['buyer' => 'GUIDE SAMPLE TRADING', 'plate' => '11가1002', 'sale_price' => 12000, 'currency' => 'EUR'],
            ],
            'contract_totals' => ['count' => 2, 'by_currency' => ['EUR' => 14000]],
            'plan_tomorrow' => "바이어 요청 차량 가격 확인\n진행 차량 검수 리포트 발송",
            'remarks' => "고정 업무\n수요일 경매장 검수 09:00~13:00",
        ], $override);
    }

    private function sheet(array $override = []): Worksheet
    {
        return WorkJournalExcel::build($this->data($override))->getActiveSheet();
    }

    private function val(Worksheet $ws, string $cell): string
    {
        return (string) $ws->getCell($cell)->getFormattedValue();
    }

    public function test_title_header_and_labels(): void
    {
        $ws = $this->sheet();

        $this->assertSame('일일 업무일지', $ws->getTitle());
        $this->assertSame('해외영업부 업무일지', $this->val($ws, 'A2'));
        $this->assertSame('SSANCAR', $this->val($ws, 'G2'));
        $this->assertSame(['작성일', '2026-11-16', '담당자', '김영업', '서명'],
            [$this->val($ws, 'A5'), $this->val($ws, 'B5'), $this->val($ws, 'D5'), $this->val($ws, 'E5'), $this->val($ws, 'G5')]);
        $this->assertSame("최종 승인\n대표 홍대표\n11-16 18:20", $this->val($ws, 'H5'));
        $this->assertSame(['시간', '위치', '진행 업무', '비고'],
            [$this->val($ws, 'A7'), $this->val($ws, 'B7'), $this->val($ws, 'C7'), $this->val($ws, 'F7')]);

        foreach (['A2:F2', 'G2:H2', 'B5:C5', 'E5:F5', 'C7:E7', 'F7:H7', 'C8:E8', 'F17:H17', 'A19:H19', 'A20:H22', 'A24:D24', 'E28:H28'] as $range) {
            $this->assertArrayHasKey($range, $ws->getMergeCells(), $range);
        }
    }

    public function test_entries_are_sorted_by_time_and_padded_to_ten_rows(): void
    {
        $ws = $this->sheet();

        $this->assertSame('9:00', $this->val($ws, 'A8'));
        $this->assertSame('사무실', $this->val($ws, 'B8'));
        $this->assertSame('바이어 요청 차량 가격 문의', $this->val($ws, 'C8'));
        $this->assertSame("11가1001\n11가1002", $this->val($ws, 'F8'));
        $this->assertSame('10:00', $this->val($ws, 'A9'));
        $this->assertSame('', $this->val($ws, 'C17'));      // 10번째 행은 빈 칸으로 남는다
        $this->assertSame('금일 계약 차량', $this->val($ws, 'A19'));
    }

    public function test_contract_block_lists_cars_and_totals(): void
    {
        $ws = $this->sheet();

        $this->assertSame(
            "GUIDE SAMPLE TRADING: 11가1001 / 판매금액 2,000 EUR\n"
            ."GUIDE SAMPLE TRADING: 11가1002 / 판매금액 12,000 EUR\n"
            .'총계약 2대 / 총판매금액 14,000 EUR',
            $this->val($ws, 'A20'),
        );
    }

    public function test_multiple_currencies_and_empty_contracts(): void
    {
        $lines = WorkJournalExcel::contractLines(
            [['buyer' => 'GUIDE SAMPLE TRADING', 'plate' => '11가1001', 'sale_price' => 1500.5, 'currency' => 'usd']],
            ['count' => 2, 'by_currency' => ['EUR' => 14000, 'USD' => 1500.5]],
        );
        $this->assertSame('GUIDE SAMPLE TRADING: 11가1001 / 판매금액 1,500.50 USD', $lines[0]);
        $this->assertSame('총계약 2대 / 총판매금액 14,000 EUR · 1,500.50 USD', $lines[1]);

        $this->assertSame('금일 계약 없음', $this->val($this->sheet(['contracts' => [], 'contract_totals' => ['count' => 0, 'by_currency' => []]]), 'A20'));
    }

    public function test_plan_and_remarks_one_line_per_row(): void
    {
        $ws = $this->sheet();

        $this->assertSame('내일 예정업무', $this->val($ws, 'A24'));
        $this->assertSame('특이사항', $this->val($ws, 'E24'));
        $this->assertSame('바이어 요청 차량 가격 확인', $this->val($ws, 'A25'));
        $this->assertSame('진행 차량 검수 리포트 발송', $this->val($ws, 'A26'));
        $this->assertSame('고정 업무', $this->val($ws, 'E25'));
        $this->assertSame('수요일 경매장 검수 09:00~13:00', $this->val($ws, 'E26'));
    }

    public function test_more_than_ten_entries_push_blocks_down(): void
    {
        $entries = [];
        for ($h = 8; $h < 20; $h++) {
            $entries[] = ['time' => sprintf('%02d:00', $h), 'location' => '사무실', 'task' => "업무 {$h}", 'note' => ''];
        }
        $ws = $this->sheet(['entries' => $entries]);

        $this->assertSame('19:00', $this->val($ws, 'A19'));
        $this->assertSame('업무 19', $this->val($ws, 'C19'));
        $this->assertArrayHasKey('C19:E19', $ws->getMergeCells());
        $this->assertSame('금일 계약 차량', $this->val($ws, 'A21'));
        $this->assertArrayHasKey('A22:H24', $ws->getMergeCells());
        $this->assertSame('내일 예정업무', $this->val($ws, 'A26'));
        $this->assertSame('FFF5F7FB', $ws->getStyle('C19')->getFill()->getStartColor()->getARGB()); // 줄무늬 이어짐
    }

    public function test_free_text_is_never_a_formula_and_signature_falls_back_to_status(): void
    {
        $ws = $this->sheet([
            'signature' => null,
            'entries' => [['time' => '09:00', 'location' => '사무실', 'task' => '=1+1', 'note' => '0012']],
        ]);

        $this->assertSame(DataType::TYPE_STRING, $ws->getCell('C8')->getDataType());
        $this->assertSame('=1+1', $ws->getCell('C8')->getValue());
        $this->assertSame('0012', $ws->getCell('F8')->getValue());
        $this->assertSame('결재 완료', $this->val($ws, 'H5'));
    }
}
