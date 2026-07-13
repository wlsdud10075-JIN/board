<?php

namespace App\Services;

use App\Models\InspectionAssignment;
use App\Models\PurchaseListing;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 지역 검차 안내 알림톡(A) — 지역별 검차 대상(draft) 차량 목록을 담당 검차원에게.
 *
 * 수신자 = 하이브리드: 그 날짜 per-date 배정(InspectionAssignment) 있으면 그 검차원(override),
 *          없으면 지역 고정 로스터(users.region + role=inspection + 활성).
 * 중복 방지 = 차량당 1회(purchase_listings.region_notified_at). ⚠️ 실발송('sent') 성공 시에만 stamp
 *   → 알림톡 off(skipped)·수신자 무전화(no_phone) 상태에서 stamp 되어 활성화 후 영영 누락되는 것 방지.
 * 발송기(BizmAlimtalkService)가 fire-and-forget 이라 여기서도 예외 안 던짐(스케줄/버튼 안전).
 */
class RegionInspectionNotifier
{
    public function __construct(private BizmAlimtalkService $alimtalk) {}

    /**
     * 그 날짜·지역의 수신 검차원 — per-date 배정 우선(override), 없으면 지역 고정 로스터.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(string $date, string $region): Collection
    {
        $assigned = InspectionAssignment::with('user')
            ->where('date', $date)->where('region', $region)
            ->get()->pluck('user')
            ->filter(fn (?User $u) => $u && $u->is_active)
            ->values();
        if ($assigned->isNotEmpty()) {
            return $assigned;
        }

        return User::where('region', $region)
            ->where('role', 'inspection')->where('is_active', true)
            ->orderBy('name')->get();
    }

    /**
     * 지역별 digest 발송. $date = 수신자 해석 기준(스케줄=내일 / 수동=관리 선택일).
     * 대상 차량 = draft + region 지정 + 미통보(region_notified_at NULL), 지역별 그룹.
     *
     * @return array{regions:int, sent:int, skipped:int, no_recipient:int}
     */
    public function run(string $date): array
    {
        $groups = PurchaseListing::where('status', 'draft')
            ->whereNotNull('region')
            ->whereNull('region_notified_at')
            ->orderBy('vehicle_number')
            ->get()
            ->groupBy('region');

        $regions = 0;
        $sent = 0;
        $skipped = 0;
        $noRecipient = 0;

        foreach ($groups as $region => $listings) {
            $recipients = $this->recipientsFor($date, (string) $region);
            if ($recipients->isEmpty()) {
                $noRecipient++;   // 수신자 없음 → stamp 안 함(배정/로스터 생기면 다음 실행에 재시도)

                continue;
            }
            $regions++;

            $vehicleList = $listings
                ->map(fn (PurchaseListing $l) => $l->vehicle_number ?: __('inspection.region_unset'))
                ->implode("\n");
            $vars = [
                '지역' => (string) $region,
                '건수' => (string) $listings->count(),
                '차량목록' => $vehicleList,
            ];

            $anySent = false;
            foreach ($recipients as $u) {
                $phone = trim((string) $u->phone);
                if ($phone === '') {
                    $skipped++;

                    continue;
                }
                $log = $this->alimtalk->send('board_region_inspection', $phone, $vars, [
                    'user_id' => $u->id,
                    'region' => (string) $region,
                ]);
                if ($log->status === 'sent') {
                    $sent++;
                    $anySent = true;
                } else {
                    $skipped++;
                }
            }

            // 실발송 성공했을 때만 dedup stamp(off/no_phone 로 skip 된 경우는 미stamp = 재시도 여지).
            if ($anySent) {
                PurchaseListing::whereIn('id', $listings->pluck('id'))->update(['region_notified_at' => now()]);
            }
        }

        return ['regions' => $regions, 'sent' => $sent, 'skipped' => $skipped, 'no_recipient' => $noRecipient];
    }
}
