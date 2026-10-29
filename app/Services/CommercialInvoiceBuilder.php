<?php

namespace App\Services;

use App\Models\PurchaseListing;
use App\Support\Instance;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * board 전용 COMMERCIAL INVOICE(xlsx) — 회사별 양식에 값만 채운다 (2026-10-29 ②, Jin 2026-10-28 「board 에서만 사용하는 파일로」).
 *
 * 양식 = `resources/templates/invoice-{heyman|ssancar}.xlsx`(Jin 제공 원본 그대로 — A13~E15 회사 계좌 블록 리터럴 포함).
 * 선택 = `config('board.instances')[APP_NAME]['invoice_template']`(`Instance::assets()` 와 같은 분기), 없으면 `board.invoice_template`(env, 로컬용).
 * 양식의 값 칸은 전부 외부 판매내역 시트 VLOOKUP 수식 → **덮어써서 값으로** 쓴다. G1(조회 키)은 비운다.
 *
 * 셀 매핑(기획서 §② 표, 두 양식 동일): E3 Date · E4 수식 유지(`="MU"&RIGHT(D18,6)`) · E5 Name · E6 ID · E7 Address · E8 Phone(둘은 ERP `GET /buyers/{id}`,
 * 없으면 **빈 칸** — 세관 서류라 「-」 금지) · E9 Euro Rate · E10 Dollar Rate · A18 차량번호(Code 대체) · B18/C18 Brand/Model(ERP 메타, 없으면 빈 칸)
 * · D18 VIN · E18 FOB = `sale_price` · F18 Shipping = `transport_fee` · **E27 `=SUM(E18:F25)-E26` 두 양식 모두**(HEYMAN 원본의 `+E26` 은 오류, Jin).
 * E24~E26·E28~E30 은 수기(비움 유지). 수식은 미리 계산하지 않는다(엑셀이 열 때 계산).
 */
class CommercialInvoiceBuilder
{
    /** 이 인스턴스의 양식 절대경로. 없으면 null(화면은 「이 인스턴스엔 양식 없음」). */
    public static function templatePath(): ?string
    {
        $file = Instance::assets()['invoice_template'] ?? config('board.invoice_template');
        if (! is_string($file) || $file === '') {
            return null;
        }
        $path = resource_path('templates/'.basename($file));

        return is_file($path) ? $path : null;
    }

    public static function fileName(PurchaseListing $l): string
    {
        $plate = (string) preg_replace('/[^0-9A-Za-z가-힣]/u', '', (string) $l->vehicle_number);

        return 'INVOICE_'.($plate !== '' ? $plate : $l->id).'_'.now()->format('Ymd').'.xlsx';
    }

    /**
     * @param  array{address?: ?string, phone?: ?string, brand?: ?string, model?: ?string}  $erp  ERP 에서 그 순간 읽은 값(board 저장 0). 없으면 빈 칸.
     * @return string xlsx 바이트
     */
    public function build(PurchaseListing $l, array $erp = []): string
    {
        $path = self::templatePath();
        if ($path === null) {
            throw new \RuntimeException('invoice template missing');
        }
        $ss = IOFactory::load($path);
        $ws = $ss->getActiveSheet();

        $blank = fn ($v) => ($v === null || trim((string) $v) === '') ? null : (string) $v;
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) $v;

        $ws->setCellValue('G1', null);   // 외부 시트 조회 키 — 값 문서엔 뜻이 없다
        $ws->setCellValue('E3', now()->format('Y-m-d'));
        $ws->setCellValue('E5', $blank($l->remitter_name));
        $ws->setCellValue('E6', $blank($l->remitter_id));
        $ws->setCellValue('E7', $blank($erp['address'] ?? null));
        $ws->setCellValue('E8', $blank($erp['phone'] ?? null));
        $ws->setCellValue('E9', $num($l->offer_rate_eur ?: ($l->offer_currency === 'EUR' ? $l->offer_rate : null)));
        $ws->setCellValue('E10', $num($l->offer_rate_usd ?: ($l->offer_currency === 'USD' ? $l->offer_rate : null)));
        $ws->setCellValue('A18', $blank($l->vehicle_number));
        $ws->setCellValue('B18', $blank($erp['brand'] ?? null));
        $ws->setCellValue('C18', $blank($erp['model'] ?? null));
        $ws->setCellValue('D18', $blank($l->vin));
        $ws->setCellValue('E18', $num($l->sale_price));
        $ws->setCellValue('F18', $num($l->transport_fee));
        $ws->setCellValue('E27', '=SUM(E18:F25)-E26');

        $writer = IOFactory::createWriter($ss, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
