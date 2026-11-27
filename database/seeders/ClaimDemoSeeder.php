<?php

namespace Database\Seeders;

use App\Models\Claim;
use App\Models\ClaimFile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * 로컬 화면 확인용 — 칸이 전부 채워진 클레임 1건(2026-11-27 Jin «내용 다 있는 걸로 더미 하나»).
 *
 *   php artisan db:seed --class=ClaimDemoSeeder      (다시 돌리면 같은 차 99데9999 를 지우고 새로 만든다)
 *
 * **local 전용**(다른 환경에서는 아무것도 안 한다). ERP 를 부르지 않는다 — 바이어 추가 정보·구매 이력·신용도는 여기 적은 가짜 값이고,
 * 첨부(계기판·손상·INVOICE·기타·Proforma Invoice)는 「예시」라고 적힌 그림·엑셀을 만들어 붙인다.
 * ⚠️ 담당자 창의 [ERP 다시 불러오기]·[ERP 에서 가져오기]를 누르면 로컬 ERP 를 실제로 부른다(이 차·바이어는 ERP 에 없어 「받지 못했습니다」가 정상).
 */
class ClaimDemoSeeder extends Seeder
{
    private const PLATE = '99데9999';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('ClaimDemoSeeder — local 환경에서만 돕니다.');

            return;
        }
        $sales = User::where('role', 'sales')->where('is_active', true)->orderByRaw("email = 'guide-demo@local.test' desc")->orderBy('id')->first();
        if (! $sales) {
            $this->command?->error('활성 영업 계정이 없습니다.');

            return;
        }
        $disk = config('board.photo_disk');

        foreach (Claim::where('vehicle_number', self::PLATE)->get() as $old) {
            Storage::disk($disk)->deleteDirectory(config('board.claims_prefix').'/'.$old->id);
            $old->delete();
        }

        $claim = Claim::create([
            'status' => Claim::REVIEWING,
            'car_erp_vehicle_id' => 999001,
            'vehicle_number' => self::PLATE,
            'vin' => 'WAUZZZ4G5DEMO9999',
            'brand' => 'Audi',
            'model_type' => 'A6 45 TDI Quattro',
            'car_erp_buyer_id' => 999001,
            'buyer_name' => 'DEMO AUTO TRADING (예시)',
            'buyer_contact_name' => 'Mr. Demo Buyer',
            'sale_price' => 18500,
            'sale_currency' => 'USD',
            'sale_date' => now()->subMonths(2)->toDateString(),
            'eta_date' => now()->subDays(9)->toDateString(),
            'total_purchase_count' => 15,
            'year_purchase_count' => 6,
            'member_since' => 2024,
            'buyer_credit_grade' => 'B',
            'buyer_credit_score' => 72,
            'margin_rate' => 6.4,
            'erp_snapshot_at' => now(),
            'purchase_history' => [
                ['month' => now()->subMonths(2)->format('Y-m'), 'model' => 'Audi A6 45 TDI Quattro', 'vin' => 'WAUZZZ4G5DEMO9999', 'vehicle_number' => self::PLATE, 'amount' => 18500, 'currency' => 'USD'],
                ['month' => now()->subMonths(3)->format('Y-m'), 'model' => 'BMW 520d', 'vin' => 'WBA5E51070DEMO001', 'vehicle_number' => '99데9001', 'amount' => 16200, 'currency' => 'USD'],
                ['month' => now()->subMonths(5)->format('Y-m'), 'model' => 'Mercedes-Benz E220d', 'vin' => 'WDD2130041DEMO002', 'vehicle_number' => '99데9002', 'amount' => 14800, 'currency' => 'EUR'],
                ['month' => now()->subMonths(6)->format('Y-m'), 'model' => 'Hyundai Palisade 2.2D', 'vin' => 'KMHR381ABDEMO0003', 'vehicle_number' => '99데9003', 'amount' => 21000, 'currency' => 'USD'],
                ['month' => now()->subMonths(8)->format('Y-m'), 'model' => 'Kia Sorento 2.2D', 'vin' => 'KNARH81BDEMO00004', 'vehicle_number' => '99데9004', 'amount' => 13900, 'currency' => 'USD'],
                ['month' => now()->subMonths(11)->format('Y-m'), 'model' => 'Volkswagen Tiguan 2.0 TDI', 'vin' => 'WVGZZZ5NDEMO00005', 'vehicle_number' => '99데9005', 'amount' => 12600, 'currency' => 'EUR'],
            ],
            // 바이어 추가 정보 — ERP `/buyers/{id}/summary` 와 같은 모양(바이어 전체 기준). 칸이 전부 보이도록 미수·환율 미입력·통화 3종을 넣었다.
            'buyer_summary' => [
                'buyer_id' => 999001,
                'vehicle_count' => 15,
                'vehicle_count_12m' => 6,
                'first_sale_date' => '2024-03-11',
                'by_currency' => [
                    ['currency' => 'USD', 'vehicle_count' => 9, 'sale_total' => 148300, 'unpaid' => 22500, 'paid_ratio' => 0.848],
                    ['currency' => 'EUR', 'vehicle_count' => 5, 'sale_total' => 68400, 'unpaid' => 0, 'paid_ratio' => 1.0],
                    ['currency' => 'KRW', 'vehicle_count' => 1, 'sale_total' => 19800000, 'unpaid' => 4800000, 'paid_ratio' => 0.758],
                ],
                'unpaid_total_krw' => 34725000,
                'paid_ratio' => 0.887,
                'unpaid_vehicle_count' => 3,
                'fx_missing_count' => 1,
                'vehicle' => ['vehicle_id' => 999001, 'currency' => 'USD', 'sale_total' => 18500, 'unpaid' => 4625, 'unpaid_ratio' => 0.25, 'fully_paid' => false],
            ],
            'buyer_summary_at' => now(),
            'salesman_user_id' => $sales->id,
            'created_by_user_id' => $sales->id,
            'arrived_at' => now()->subDays(8)->toDateString(),
            'received_km' => 128450,
            'content' => "도착 후 시운전에서 엔진 경고등 점등, 변속 충격 발생.\n현지 정비소 점검 결과 DPF 막힘과 미션 마운트 손상 진단. 수리 견적 USD 1,950 요청.",
            'inspection_result' => '출고 전 검차: 외관 스크래치 2곳(운전석 도어·리어 범퍼) 기록. 경고등 없음, 시운전 정상. 검차 영상 1건·사진 42장.',
            'notes' => '2024년부터 거래한 바이어. 분기마다 3~4대 구매, 지난 클레임 1건(2026-05, USD 300 합의).',
            'review_notes' => 'DPF 는 주행 조건에 따라 도착 후에도 생길 수 있는 항목. 미션 마운트는 검차 영상에서 확인되지 않아 일부 인정이 타당.',
            'claim_amount' => 1950,
            'claim_currency' => 'USD',
            'refundable_amount' => 900,
            'cs_proposed_amount' => 700,
            'result' => '미션 마운트 수리비 일부 인정 — USD 700 을 다음 구매 대금에서 차감.',
            'result_reason' => '검차 기록에 없는 손상이나 운송 중 발생 가능성 있음. 장기 거래 바이어이고 이 차 미수 25% 가 남아 있어 차감 방식 제안.',
            'compensation_type' => 'credit',
            'bank_name' => 'DEMO BANK (예시)',
            'bank_iban' => 'DE00 0000 0000 0000 0000 00',
            'bank_swift' => 'DEMODEFFXXX',
            'bank_phone' => '+00 000 000 0000',
            'bank_address' => 'Demo Street 1, Sample City',
        ]);

        $n = 0;
        foreach ([
            [ClaimFile::KIND_ODOMETER, '계기판 128,450 km', [40, 52, 70]],
            [ClaimFile::KIND_DAMAGE, '엔진 경고등', [120, 50, 40]],
            [ClaimFile::KIND_DAMAGE, '미션 마운트', [90, 70, 40]],
            [ClaimFile::KIND_DAMAGE, '하부 사진', [60, 80, 60]],
            [ClaimFile::KIND_INVOICE, '정비소 INVOICE USD 1,950', [60, 60, 110]],
            [ClaimFile::KIND_OTHER, '바이어 결제 영수증', [80, 80, 80]],
        ] as [$kind, $label, $rgb]) {
            $name = 'demo-'.(++$n).'.png';
            $this->put($claim, $kind, $name, $this->image($label, $rgb), 'image/png', $sales->id);
        }
        $this->put($claim, ClaimFile::KIND_PROFORMA, 'proforma-invoice-99데9999-demo.xlsx', $this->proforma($claim),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $sales->id);

        $this->command?->info('클레임 더미 #'.$claim->id.' ('.self::PLATE.') — 영업 '.$sales->name.' · 상태 검토 중 · 첨부 '.$claim->files()->count().'건');
        $this->command?->info('보기: /claims?tab=open 에서 '.self::PLATE.' (클레임 담당자 계정)');
    }

    private function put(Claim $claim, string $kind, string $name, string $bytes, string $mime, int $userId): void
    {
        $path = config('board.claims_prefix').'/'.$claim->id.'/'.$kind.'/'.$name;
        Storage::disk(config('board.photo_disk'))->put($path, $bytes);
        $claim->files()->create(['kind' => $kind, 's3_path' => $path, 'original_name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'uploaded_by_user_id' => $userId]);
    }

    /** 「예시」라고 적힌 단색 그림 — 실제 사진처럼 보이지 않게. */
    private function image(string $label, array $rgb): string
    {
        $im = imagecreatetruecolor(640, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
        $white = imagecolorallocate($im, 255, 255, 255);
        imagestring($im, 5, 24, 24, 'CLAIM DEMO - SAMPLE IMAGE', $white);
        imagestring($im, 4, 24, 52, 'board local seeder (not a real photo)', $white);
        $font = collect(['C:/Windows/Fonts/malgun.ttf', '/usr/share/fonts/truetype/nanum/NanumGothic.ttf'])->first(fn ($f) => is_file($f));
        if ($font && function_exists('imagettftext')) {
            imagettftext($im, 26, 0, 24, 250, $white, $font, '예시 · '.$label);
        }
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    /** 예시 Proforma Invoice(엑셀) — ERP 가 주는 양식이 아니라 칸만 흉내 낸 것. */
    private function proforma(Claim $c): string
    {
        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('PROFORMA INVOICE');
        $rows = [
            ['PROFORMA INVOICE (DEMO — board local seeder)'], [],
            ['Buyer', $c->buyer_name], ['Sale date', (string) $c->sale_date?->format('Y-m-d')], [],
            ['Vehicle No.', 'VIN', 'Model', 'Currency', 'Sale price', 'Freight', 'Total'],
            [$c->vehicle_number, $c->vin, $c->brand.' '.$c->model_type, 'USD', 16460, 2040, 18500],
        ];
        $s->fromArray($rows, null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'pi');
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
