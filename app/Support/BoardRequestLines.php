<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * ERP `GET /requests` 응답을 **라인(차량) 단위로 평탄화** — 상단 종(`notify.bell`)과 웹푸시 스케줄러
 * (`board:push-request-results`)가 같은 모양을 쓴다. 두 곳이 각자 읽으면 "종엔 뜨는데 푸시는 안 온다"가 생긴다.
 *
 * 묶음(판매대금확인 N대)도 차량별 결과가 다를 수 있어(부분 확인·부분 거절) 라인이 단위다.
 * 값은 ERP 그대로 — 상태 재계산·완료 coerce 금지(SKILLS §14-2).
 */
class BoardRequestLines
{
    /** 결과로 치는 라인 상태 — ERP 사람이 「확인」 또는 「거절」을 누른 것. */
    public const RESULT_STATUSES = ['done', 'rejected'];

    /**
     * @param  array  $data  `GET /requests` 봉투의 data(`{count, requests[]}`)
     * @return array<int, array{key:string,type:string,vehicle_number:string,buyer_name:?string,amount:mixed,status:string,requested_at:?string,resolved_at:?string,resolved_by:?string,reason:?string}>
     */
    public static function flatten(?array $data): array
    {
        $out = [];
        foreach ((array) data_get($data, 'requests', []) as $batch) {
            foreach ((array) data_get($batch, 'vehicles', []) as $line) {
                $out[] = [
                    'key' => data_get($batch, 'batch_id').'|'.data_get($line, 'vehicle_number'),
                    'type' => (string) data_get($batch, 'type'),
                    'vehicle_number' => (string) data_get($line, 'vehicle_number'),
                    'buyer_name' => data_get($batch, 'buyer_name'),
                    'amount' => data_get($line, 'amount_krw') ?? data_get($batch, 'amount_krw'),
                    'status' => (string) data_get($line, 'status'),
                    'requested_at' => data_get($batch, 'requested_at'),
                    // 처리 시각 — ERP 신버전은 resolved_at(확인·거절 공통), 구버전은 확인 라인에 confirmed_at 만.
                    'resolved_at' => data_get($line, 'resolved_at') ?? data_get($line, 'confirmed_at'),
                    'resolved_by' => data_get($line, 'resolved_by') ?? data_get($batch, 'resolved_by'),
                    'reason' => data_get($line, 'reject_reason') ?? data_get($batch, 'reject_reason'),
                ];
            }
        }

        return $out;
    }

    public static function isResult(array $line): bool
    {
        return in_array($line['status'] ?? '', self::RESULT_STATUSES, true) && ! empty($line['resolved_at']);
    }

    /** 처리 시각이 $ts 보다 뒤인가. $ts null = 전부. 파싱 불가 시각은 false(미읽음·토스트·푸시 어디에도 세지 않는다). */
    public static function resolvedAfter(array $line, ?string $ts): bool
    {
        if ($ts === null) {
            return true;
        }
        try {
            return Carbon::parse($line['resolved_at'])->gt(Carbon::parse($ts));
        } catch (\Throwable) {
            return false;
        }
    }
}
