<?php

namespace App\Support;

/**
 * 카카오 알림톡 템플릿 — 문구를 데이터로 보관(단일 출처, car-erp AlimtalkTemplates 미러).
 *
 * ⚠️ 여기 body 문구는 BizM 콘솔에 등록·승인된 템플릿과 **글자 하나까지 동일**해야 한다.
 *    알림톡은 발송 msg 가 승인 템플릿과 다르면 반려/실패한다. 초안 원본 =
 *    docs/operations/alimtalk-templates-draft.md (BizM 등록 시 그대로 복붙). 문구 수정 시 양쪽 동시 반영.
 *
 * 변수 치환: 본문의 `#{변수}` 를 render() 가 전달값으로 바꾼다(BizM 도 같은 자리표시자).
 * 가변 목록(차량목록)은 한 변수에 개행으로 담는다(car-erp 담당자실적 패턴).
 */
class AlimtalkTemplates
{
    public const TEMPLATES = [
        // 지역 검차 안내 — 지역별 검차 대상(draft) 차량 목록을 그 지역 배정 검차원에게.
        'board_region_inspection' => [
            'name' => '지역 검차 안내',
            'recipient' => 'inspection',
            'vars' => ['지역', '건수', '차량목록'],
            'title' => '',
            'body' => "[검차 안내] #{지역}\n\n#{지역} 지역 검차 담당으로 등록하신 회원님께, 검차가 접수된 차량 목록을 안내드립니다.\n\n■ 지역: #{지역}\n■ 검차 대상 #{건수}대\n#{차량목록}\n\nssancar.com에서 검차 결과를 등록해 주세요.",
        ],
        // 전달 대기 — ssancar 자동전이(사진/영상 업로드 감지) 시 그 매물 작성 영업에게.
        'board_forward_ready' => [
            'name' => '전달 대기 안내',
            'recipient' => 'sales',
            'vars' => ['차량번호'],
            'title' => '',
            'body' => "[전달 대기] #{차량번호}\n\n회원님이 board에 등록하신 매물 #{차량번호}의 사진/영상 업로드가 완료되어 바이어 전달 대기 상태입니다.\n\nboard에서 확인 후 바이어에게 전달해 주세요.",
        ],
    ];

    /** `#{변수}` 치환 후 본문 반환. 없는 변수는 그대로 둔다(누락 방어). */
    public static function render(string $code, array $vars): string
    {
        $body = self::TEMPLATES[$code]['body'] ?? '';
        foreach ($vars as $key => $val) {
            $body = str_replace('#{'.$key.'}', (string) $val, $body);
        }

        return $body;
    }
}
