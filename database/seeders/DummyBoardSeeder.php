<?php

namespace Database\Seeders;

use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 지역필터 로컬 검증용 더미 데이터.
 *   php artisan db:seed --class=DummyBoardSeeder
 *
 * 영업 5명 × 10건 = 50건 / 현지확인 5명 / 오늘 지역배정(동행·미배정 시연).
 * 재실행 안전: 기존 더미(이 시더가 만든 영업/검차)의 매입예정·배정을 지우고 다시 만든다.
 */
class DummyBoardSeeder extends Seeder
{
    public function run(): void
    {
        // 6개 지역에 클러스터링 (배정 겹침/동행이 보이도록)
        $regions = ['경기 수원시', '서울특별시', '인천광역시', '경기 성남시', '부산광역시', '경기 고양시'];

        // ─── 영업 5명 ───
        $sales = [];
        foreach (range(1, 5) as $n) {
            $sales[] = User::updateOrCreate(
                ['email' => "sales{$n}@dummy.test"],
                ['name' => "영업{$n}", 'role' => 'sales', 'permission' => 'user',
                    'is_active' => true, 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
        }

        // ─── 현지확인 5명 ───
        $inspectors = [];
        foreach (range(1, 5) as $n) {
            $inspectors[] = User::updateOrCreate(
                ['email' => "insp{$n}@dummy.test"],
                ['name' => "검차{$n}", 'role' => 'inspection', 'permission' => 'user',
                    'is_active' => true, 'password' => Hash::make('password'), 'email_verified_at' => now()],
            );
        }

        // ─── 재실행 안전: 기존 더미 매입예정·배정 제거 (VIN unique 충돌 방지 위해 forceDelete) ───
        $salesIds = collect($sales)->pluck('id');
        PurchaseListing::withoutGlobalScopes()->whereIn('created_by_user_id', $salesIds)->forceDelete();
        InspectionAssignment::whereIn('user_id', collect($inspectors)->pluck('id'))->delete();

        // ─── 각 영업 10건씩 = 50건 (지역 랜덤 분배) ───
        $shipping = config('board.shipping_options');
        $vinSeq = 0;
        foreach ($sales as $si => $u) {
            foreach (range(1, 10) as $k) {
                $region = $regions[array_rand($regions)];
                $source = ($k % 3 === 0) ? 'auction' : 'encar';
                $vinSeq++;

                $listing = new PurchaseListing([
                    'created_by_user_id' => $u->id,
                    'source' => $source,
                    'region' => $region,
                    'vehicle_number' => sprintf('%02d가%04d', $si + 10, 1000 + $k),
                    'vin' => 'DUMMY'.str_pad((string) $vinSeq, 8, '0', STR_PAD_LEFT),
                    'car_cost' => rand(80, 250) * 100_000,   // 8,000,000 ~ 25,000,000
                    'discount_rate' => rand(0, 5),
                    'shipping_usd' => $shipping[array_rand($shipping)],
                    'encar_url' => $source === 'encar' ? 'https://encar.com/dummy'.$vinSeq : null,
                    'c_no' => $source === 'encar' ? (string) rand(6_000_000, 6_999_999) : null,
                    'auction_venue' => $source === 'auction' ? '롯데' : null,
                    'lot_number' => $source === 'auction' ? 'A-'.rand(1000, 9999) : null,
                    'status' => 'draft',
                    'buyer_verdict' => 'none',
                ]);
                $listing->final_price = $listing->totalKrw();   // 최종금액 KRW 스냅샷
                $listing->save();
            }
        }

        // ─── 오늘 지역 배정 (동행·중복·미배정 시연) ───
        $today = now()->toDateString();
        [$i1, $i2, $i3, $i4, $i5] = $inspectors;
        $map = [
            '경기 수원시' => [$i1, $i2],         // 동행 2명
            '서울특별시' => [$i1, $i3, $i4],      // 3명(최대)
            '인천광역시' => [$i2],
            '경기 성남시' => [$i3, $i5],
            '부산광역시' => [$i5],
            // '경기 고양시' = 미배정 → 관리/super 에게만 노출, 검차5명 누구에게도 안 보임
        ];
        foreach ($map as $region => $users) {
            foreach ($users as $u) {
                InspectionAssignment::create(['date' => $today, 'region' => $region, 'user_id' => $u->id]);
            }
        }

        $this->command->info('더미 완료: 영업5명×10건=50건 / 현지확인5명 / 오늘('.$today.') 지역배정.');
        $this->command->info('로그인(비번 password): sales1~5@dummy.test, insp1~5@dummy.test');
        $this->command->info('검차별 배정: 검차1=수원+서울 / 검차2=수원+인천 / 검차3=서울+성남 / 검차4=서울 / 검차5=성남+부산 / 고양=미배정');
    }
}
