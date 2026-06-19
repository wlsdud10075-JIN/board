<?php

namespace App\Services;

use App\Models\PurchaseListing;
use App\Models\Scopes\SalesmanScope;
use Illuminate\Support\Facades\DB;

/**
 * 바이어 회신(verdict) 적용 단일 경로 — A(수동 /verdicts), C(폴러), 향후 B(webhook) 공용.
 *
 * 락 ③(이중적용 차단): apply() 는 트랜잭션 + 행잠금 + status=awaiting_buyer 조건부.
 * 이미 처리된 차(상태가 바뀜)는 false 반환 → 폴러·수동·중복실행이 같은 차를 두 번 못 덮음.
 * 상태/verdict 변경은 PurchaseListing updated 옵저버가 감사기록(user_id=null=시스템 or 로그인).
 */
class VerdictService
{
    /**
     * 차 1대에 verdict 적용 (race-safe). awaiting_buyer 일 때만.
     *
     * @return bool 적용됨 여부 (이미 처리됐으면 false)
     */
    public function apply(int $listingId, string $verdict): bool
    {
        if (! in_array($verdict, ['accepted', 'rejected'], true)) {
            return false;
        }

        return DB::transaction(function () use ($listingId, $verdict) {
            $l = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
                ->where('id', $listingId)
                ->where('status', 'awaiting_buyer')
                ->lockForUpdate()
                ->first();

            if (! $l) {
                return false;   // 이미 처리됨/대상 아님
            }

            $l->buyer_verdict = $verdict;
            $l->status = $verdict === 'accepted' ? 'accepted' : 'rejected';
            $l->save();   // 가드: awaiting_buyer→accepted(verdict=accepted 충족)/→rejected

            return true;
        });
    }

    /** respond.io 컨택트(스파인=contact id) 로 회신대기 차 단일이면 적용. (C 폴링) */
    public function applyByContact(string $contactId, string $verdict, ?string $channel = null): array
    {
        return $this->applyByRef('respond_contact_id', $contactId, $verdict, null, $channel);
    }

    /** 대화 id 로 회신대기 차 단일이면 적용. (B webhook 용 — respond.io 가 conversation 동반 시) */
    public function applyByConversation(string $convId, string $verdict, ?string $vehicleNumber = null, ?string $channel = null): array
    {
        return $this->applyByRef('respond_conversation_id', $convId, $verdict, $vehicleNumber, $channel);
    }

    /**
     * 공통: 스파인 컬럼으로 회신대기 차 조회 → 단일=적용, 다중=ambiguous(직렬화/상담원), 없음=no_match.
     *
     * @return array{status:string, listing_id:?int}
     */
    private function applyByRef(string $column, string $value, string $verdict, ?string $vehicleNumber, ?string $channel): array
    {
        if ($value === '' || ! in_array($verdict, ['accepted', 'rejected'], true)) {
            return ['status' => 'no_match', 'listing_id' => null];
        }

        $q = PurchaseListing::withoutGlobalScope(SalesmanScope::class)
            ->where($column, $value)
            ->where('status', 'awaiting_buyer');

        if ($vehicleNumber !== null && $vehicleNumber !== '') {
            $q->where('vehicle_number', $vehicleNumber);
        }
        if ($channel !== null) {
            $q->where('verdict_channel', $channel);
        }

        $cands = $q->get();

        if ($cands->isEmpty()) {
            return ['status' => 'no_match', 'listing_id' => null];
        }
        if ($cands->count() > 1) {
            return ['status' => 'ambiguous', 'listing_id' => null];
        }

        $l = $cands->first();
        $ok = $this->apply($l->id, $verdict);

        return $ok
            ? ['status' => 'applied:'.$verdict, 'listing_id' => $l->id]
            : ['status' => 'no_match', 'listing_id' => null];
    }
}
