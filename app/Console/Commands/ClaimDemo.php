<?php

namespace App\Console\Commands;

use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use App\Models\Setting;
use App\Models\User;
use App\Services\CarErpReadService;
use App\Services\SsancarMediaService;
use Illuminate\Console\Command;

/**
 * 클레임 로컬 테스트 준비(2026-11-11 Jin) — **local 전용**.
 * 로컬 ERP 의 판매 차량 1대(시스템관리자 계정의 ERP 매핑 영업 명의)를 board 매입예정 행으로 만들고,
 * 그 행을 ssancar.com 실제 INSPECTED 검차글(wr_id)에 연결한다 → 포털 판매내역에서 [클레임 접수]하면
 * 담당자 드로어·대표 화면 「우리 검차」 열에 실제 검차 영상·사진이 뜬다.
 * ssancar 호출 = 연결 확인 1회뿐(외부 API 절제). 클레임 자체는 만들지 않는다 — 접수부터 화면으로 해 본다.
 *
 *   php artisan board:claim-demo 1234                         # 검차글 wr_id
 *   php artisan board:claim-demo "https://www.ssancar.com/bbs/board.php?bo_table=inspected&wr_id=1234"
 *   php artisan board:claim-demo 1234 --plate=60마6005 --user=wlsdud10074@naver.com
 */
class ClaimDemo extends Command
{
    protected $signature = 'board:claim-demo {wr : ssancar INSPECTED 검차글 wr_id 또는 게시글 URL}
        {--plate=60마6005 : 연결할 로컬 ERP 판매 차량번호}
        {--user=wlsdud10074@naver.com : 클레임을 접수·처리할 board 계정(포털 명의 + 클레임 담당자로 지정)}';

    protected $description = '클레임 로컬 테스트 — ERP 판매 차량을 ssancar 검차글과 연결(local 전용)';

    public function handle(CarErpReadService $erp, SsancarMediaService $ssancar): int
    {
        if (! app()->environment('local')) {
            $this->error('local 환경에서만 실행합니다.');

            return self::FAILURE;
        }
        $wr = (string) $this->argument('wr');
        if (preg_match('/wr_id=(\d+)/', $wr, $m)) {
            $wr = $m[1];
        }
        if (! ctype_digit($wr)) {
            $this->error('wr_id(숫자) 또는 wr_id= 가 들어 있는 게시글 URL 을 넣으세요.');

            return self::FAILURE;
        }

        $user = User::where('email', (string) $this->option('user'))->first();
        if (! $user) {
            $this->error('계정 없음: '.$this->option('user'));

            return self::FAILURE;
        }
        $email = $user->car_erp_salesman_email ?: $user->email;
        $plate = (string) $this->option('plate');

        $sales = $erp->sales($email);
        $row = collect((array) data_get($sales['data'] ?? null, 'data', []))
            ->first(fn ($r) => preg_replace('/\s+/u', '', (string) data_get($r, 'vehicle_number')) === preg_replace('/\s+/u', '', $plate));
        if (! $row) {
            $this->error("로컬 ERP 에서 {$email} 명의 판매 차량 {$plate} 을 찾지 못했습니다(ERP 8001 이 떠 있는지, --plate 확인).");

            return self::FAILURE;
        }

        // ssancar 연결 확인 1회.
        $media = $ssancar->fetch('inspected', $wr);
        $this->line('ssancar 검차글 wr_id='.$wr.' → 영상 '.count($media['videos'] ?? []).' · 사진 '.count($media['photos'] ?? []));
        if (($media['videos'] ?? []) === [] && ($media['photos'] ?? []) === []) {
            $this->warn('미디어가 비어 있습니다 — wr_id 확인. 연결은 그대로 진행합니다(화면엔 「검차 미디어 없음」).');
        }

        $listing = PurchaseListing::withoutGlobalScope(SalesmanScope::class)->withTrashed()
            ->where('vehicle_number', (string) $row['vehicle_number'])->first();
        $attrs = [
            'created_by_user_id' => $user->id,
            'ssancar_ref' => 'wr_id:'.$wr,
            'car_erp_vehicle_id' => (int) $row['vehicle_id'],
            'buyer_name' => data_get($row, 'buyer'),
        ];
        if ($listing) {
            $listing->restore();
            $listing->forceFill($attrs)->saveQuietly();
        } else {
            $listing = new PurchaseListing;
            $listing->forceFill($attrs + [
                'vehicle_number' => (string) $row['vehicle_number'],
                'vin' => data_get($row, 'vin') ?: null,
                'source' => 'encar', 'origin' => 'encar', 'status' => 'synced', 'buyer_verdict' => 'accepted',
            ])->saveQuietly();
        }

        $ids = User::claimHandlerIds();
        if (! in_array($user->id, $ids, true)) {
            $ids[] = $user->id;
            Setting::updateOrCreate(['key' => 'claim_handler_user_ids'], ['value' => implode(',', $ids), 'type' => 'string', 'description' => '클레임 탭 담당자 user id 목록(쉼표)']);
        }

        $this->info("✅ 매입예정 #{$listing->id} {$listing->vehicle_number}(ERP {$row['vehicle_id']}) ↔ ssancar 검차글 {$wr} 연결 · {$user->name}({$user->email}) 클레임 담당자 지정");
        $this->line("다음: {$user->email} 로 로그인 → 포털 판매내역 → 「{$listing->vehicle_number}」 검색 → [클레임 접수].");

        return self::SUCCESS;
    }
}
