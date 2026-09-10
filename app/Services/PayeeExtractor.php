<?php

namespace App\Services;

/**
 * 첨부사진에서 읽어낸 계좌 후보의 **판정**을 담당한다 (2026-09-10).
 *
 * 🚨 안전장치는 여기(코드)가 한다 — 프롬프트가 아니라. 파일럿에서 프롬프트에 배제 규칙을 넣고도
 *    모델이 "계좌번호(Account No.)" 칸 첫 줄의 **전화번호**를 계좌로 골랐다(해상도를 올려도 동일).
 *    판독은 모델이, 채택 여부는 코드가 정한다.
 */
class PayeeExtractor
{
    /** 이미지 1장에 쓰는 프롬프트. 출력은 JSON 한 덩어리. */
    public const PROMPT = <<<'TXT'
이 이미지에서 계좌 정보를 찾아 JSON만 출력하라. 설명 금지.
형식:
{"found":true,"car_account":{"bank":"","number":"","holder":""},
 "fee_account":{"bank":"","number":"","holder":"","label":""},
 "registration_owner":"","note":""}

규칙:
- car_account = 차량대금(차값)을 보낼 계좌. 해당 없으면 null.
- fee_account = 매도비·차량이전비·알선수수료 계좌. **그런 라벨이 이미지에 실제로 적혀 있을 때만** 채우고,
  label 에 그 라벨 문구를 그대로 옮겨라. 라벨이 없으면 fee_account 는 null 이다.
- 계좌가 전혀 없으면 {"found":false}.
- registration_owner = 자동차등록증·사업자등록증이 보이면 그 소유자/상호. 없으면 빈 문자열.
- 0으로 시작하는 번호(02-/031-/010 등)는 전화번호다. 1566-/1588-/1544- 는 고객센터다. 계좌가 아니다.
- 한 칸에 숫자가 여럿이면 "(계좌번호)" 표기가 붙은 것, 자릿수가 긴 것을 택하라.
- "평생계좌"는 휴대폰 기반 별칭이다. 정식 계좌번호가 있으면 그쪽을 택하라.
TXT;

    /** 매도비 계좌로 인정하는 라벨 키워드 — 하나라도 있어야 fee 로 보낸다. */
    private const FEE_LABEL_KEYWORDS = ['매도비', '이전비', '수수료', '알선'];

    /** 은행명 정규화 — 모델은 `WOORIBANK`·`IBK 기업은행` 처럼 제각각 뱉는다. 입력칸 datalist 값에 맞춘다. */
    private const BANK_ALIASES = [
        '국민' => '국민은행', 'KB' => '국민은행', 'KOOKMIN' => '국민은행',
        '신한' => '신한은행', 'SHINHAN' => '신한은행',
        '우리' => '우리은행', 'WOORI' => '우리은행',
        '하나' => '하나은행', 'HANA' => '하나은행', 'KEB' => '하나은행',
        '농협' => '농협', 'NH' => '농협',
        '기업' => 'IBK기업은행', 'IBK' => 'IBK기업은행', 'INDUSTRIAL' => 'IBK기업은행',
        '우체국' => '우체국',
        '카카오' => '카카오뱅크', 'KAKAO' => '카카오뱅크',
        '토스' => '토스뱅크', 'TOSS' => '토스뱅크',
        '새마을' => '새마을금고',
        '부산' => '부산은행',
        'SC제일' => 'SC제일은행',
        '시티' => '시티은행', 'CITI' => '시티은행',
    ];

    /**
     * 모델 응답(JSON 문자열) 1장분을 후보/거절로 가른다.
     *
     * @return array{candidates: list<array>, rejected: list<array>, registration_owner: string}
     */
    public function parseOne(string $raw, int $photoId): array
    {
        $data = $this->decode($raw);
        $out = ['candidates' => [], 'rejected' => [], 'registration_owner' => ''];
        if ($data === null || ($data['found'] ?? false) !== true) {
            return $out;
        }
        $out['registration_owner'] = trim((string) ($data['registration_owner'] ?? ''));

        foreach (['car', 'fee'] as $role) {
            $acc = $data[$role.'_account'] ?? null;
            if (! is_array($acc)) {
                continue;
            }
            $number = trim((string) ($acc['number'] ?? ''));
            if ($number === '') {
                continue;
            }
            $reason = $this->rejectReason($number);
            if ($reason !== null) {
                $out['rejected'][] = ['number' => $number, 'reason' => $reason, 'source_photo_id' => $photoId];

                continue;
            }
            $out['candidates'][] = [
                // 🚨 매도비는 라벨이 실제로 있을 때만. 파일럿에서 라벨 없는 통장사본 2건을 모델이 매도비로 넣었다.
                //    차값 계좌를 매도비로 잘못 보내면 **차값이 안 나간다**.
                'role' => ($role === 'fee' && $this->hasFeeLabel((string) ($acc['label'] ?? ''))) ? 'fee' : 'car',
                'bank' => $this->normalizeBank((string) ($acc['bank'] ?? '')),
                'number' => $number,
                'holder' => trim((string) ($acc['holder'] ?? '')),
                'source_photo_ids' => [$photoId],
                'warnings' => [],
            ];
        }

        return $out;
    }

    /**
     * 장별 결과를 합쳐 `payee_suggestions` 에 넣을 최종 구조를 만든다.
     *
     * @param  list<array>  $perPhoto  parseOne() 결과들
     */
    public function merge(array $perPhoto): array
    {
        $owners = array_values(array_filter(array_map(fn ($r) => $r['registration_owner'], $perPhoto)));
        $owner = $owners[0] ?? '';

        $candidates = [];
        $rejected = [];
        foreach ($perPhoto as $r) {
            foreach ($r['rejected'] as $x) {
                $rejected[] = $x;
            }
            foreach ($r['candidates'] as $c) {
                $key = $this->digits($c['number']);
                if (isset($candidates[$key])) {
                    // 같은 계좌가 여러 장에 나오면 후보를 늘리지 않고 근거 사진만 더한다.
                    $candidates[$key]['source_photo_ids'] = array_values(array_unique(
                        array_merge($candidates[$key]['source_photo_ids'], $c['source_photo_ids'])
                    ));
                    if ($c['role'] === 'fee') {
                        $candidates[$key]['role'] = 'fee';
                    }

                    continue;
                }
                $candidates[$key] = $c;
            }
        }

        // 예금주 ↔ 등록증 소유자 대조 — 버리지 않고 경고만 단다(사람이 확인한다).
        foreach ($candidates as $k => $c) {
            if ($owner !== '' && $c['holder'] !== '' && ! $this->ownerMatches($c['holder'], $owner)) {
                $candidates[$k]['warnings'][] = 'owner_mismatch';
            }
        }

        return [
            'extracted_at' => now()->toIso8601String(),
            'registration_owner' => $owner,
            'candidates' => array_values($candidates),
            'rejected' => $rejected,
        ];
    }

    /** 계좌로 볼 수 없는 이유. null 이면 통과. */
    private function rejectReason(string $number): ?string
    {
        if (preg_match('/[^0-9\-\s]/', $number)) {
            return 'not_numeric';
        }
        $d = $this->digits($number);
        // 0 으로 시작 = 지역번호(02·031)·휴대폰(010). 은행 계좌번호는 1~9 로 시작한다.
        if (str_starts_with($d, '0')) {
            return 'phone_pattern';
        }
        if (preg_match('/^1(566|588|544|577|599)/', $d)) {
            return 'call_center';
        }
        if (strlen($d) < 10 || strlen($d) > 17) {
            return 'bad_length';
        }

        return null;
    }

    private function hasFeeLabel(string $label): bool
    {
        foreach (self::FEE_LABEL_KEYWORDS as $kw) {
            if (str_contains($label, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeBank(string $bank): string
    {
        $s = strtoupper(str_replace(' ', '', $bank));
        if ($s === '') {
            return '';
        }
        foreach (self::BANK_ALIASES as $needle => $canonical) {
            if (str_contains($s, strtoupper($needle))) {
                return $canonical;
            }
        }

        return trim($bank);
    }

    /** 상호 표기 흔들림(주식회사·(주)·공백)을 걷어내고 비교한다. */
    private function ownerMatches(string $holder, string $owner): bool
    {
        $norm = fn (string $s) => str_replace(['주식회사', '(주)', '㈜', ' ', '님'], '', $s);
        $a = $norm($holder);
        $b = $norm($owner);
        if ($a === '' || $b === '') {
            return true;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    private function digits(string $s): string
    {
        return preg_replace('/\D/', '', $s) ?? '';
    }

    /** 모델이 ```json 펜스를 붙여 주는 경우가 있어 걷어낸다. */
    private function decode(string $raw): ?array
    {
        $s = trim($raw);
        if (str_starts_with($s, '```')) {
            $s = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $s);
        }
        $data = json_decode((string) $s, true);

        return is_array($data) ? $data : null;
    }
}
