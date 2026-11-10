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
            'body' => "[사내 업무용] 검차 배정 안내 #{지역}\n\n담당자님, 배정된 지역에 검차가 접수되어 안내드립니다.\n\n■ 지역: #{지역}\n■ 검차 대상: #{건수}대\n#{차량목록}\n\n사내 업무 시스템(board)에서 검차 결과를 등록해 주세요.",
        ],
        // 전달 대기 — ssancar 자동전이(사진/영상 업로드 감지) 시 그 매물 작성 영업에게.
        'board_forward_ready' => [
            'name' => '전달 대기 안내',
            'recipient' => 'sales',
            'vars' => ['차량번호'],
            'title' => '',
            'body' => "[사내 업무용] 전달 대기 안내 #{차량번호}\n\n담당자님, 등록하신 매물 #{차량번호}의 사진/영상 업로드가 완료되어 바이어 전달 대기 상태입니다.\n\n사내 업무 시스템(board)에서 확인 후 바이어에게 전달해 주세요.",
        ],
        // ── 클레임 2종 (Jin 2026-11-10, 정본 = meetings/plan-claims-2026-11.md §16 B·§19 P) ──
        // ① 승인요청 → 대표(approval_title=대표 계정 users.phone). WL 버튼 1개 = 서명 링크(5일), 등록 URL `{board 도메인}/a/claim/#{url}`.
        //    ⚠️ 발송 URL 은 등록 접두와 글자 단위로 같아야 한다(K108) — 링크는 Claim::approvalLinkFor() 가 APP_URL 로 고정해 만든다.
        'board_claim_approval_request' => [
            'name' => '클레임 승인요청',
            'recipient' => 'ceo',
            'vars' => ['차량번호', '바이어', '청구액', '담당자'],
            'title' => '',
            'body' => "[사내 업무용] 클레임 승인 요청 #{차량번호}\n\n대표님, 바이어 클레임 처리안이 결재를 기다리고 있습니다.\n\n■ 차량: #{차량번호}\n■ 바이어: #{바이어}\n■ 청구 금액: #{청구액}\n■ 담당자: #{담당자}\n\n아래 버튼으로 검차 영상·바이어 증빙·처리 보고서를 확인하신 뒤 승인/반려해 주세요.\n사내 업무 시스템(board)에서 클레임 담당자가 승인 요청 시 발송됩니다.",
            'button' => [
                ['name' => '승인/반려 바로가기', 'type' => 'WL', 'register_path' => '/a/claim/'],
            ],
        ],
        // ② 결재 결과 → 담당자 + 접수 영업. `#{결과}`(승인/반려)·`#{사유}` 로 한 템플릿이 둘 다 덮는다(승인이면 사유 「—」 — 빈 변수 금지).
        'board_claim_decision' => [
            'name' => '클레임 결재 결과',
            'recipient' => 'handler_sales',
            'vars' => ['차량번호', '바이어', '결과', '사유'],
            'title' => '',
            'body' => "[사내 업무용] 클레임 결재 결과 #{차량번호}\n\n담당자님, 요청하신 클레임 처리안의 대표 결재 결과를 안내드립니다.\n\n■ 차량: #{차량번호}\n■ 바이어: #{바이어}\n■ 결과: #{결과}\n■ 사유: #{사유}\n\n사내 업무 시스템(board) 클레임 화면에서 상세를 확인해 주세요.\n클레임 담당자와 접수 영업에게 발송됩니다.",
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
