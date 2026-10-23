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
    /**
     * 이미지 1장에 쓰는 프롬프트. 출력은 JSON 한 덩어리.
     *
     * 🚨 **"~할 때만 채워라 / 없으면 null" 같은 이중 부정을 쓰지 말 것.** 처음에 fee_account 를
     *    그렇게 썼더니(「그런 라벨이 실제로 적혀 있을 때만」) 모델이 지나치게 보수적으로 굴어
     *    **라벨이 또렷한 두 계좌 사진에서도 매도비를 통째로 빠뜨렸다**(2026-09-10 운영 실측).
     *    「여러 개 보이면 하나도 빠뜨리지 말고」로 바꾸자 둘 다 잡았다. 라벨 검증은 어차피
     *    코드(hasFeeLabel)가 하므로, 프롬프트는 **다 담게** 하고 판정은 코드에 맡긴다.
     * ⚠️ 이 상수를 고치면 표본으로 재검증할 것 — 테스트(가짜 응답)로는 이 계열 회귀가 안 잡힌다.
     *
     * 2026-10-22 Jin — **계좌를 전부 담고 사람이 고른다**(accounts[]). 종전엔 모델이 차값 계좌 1개를 골랐는데,
     *    IBK 평생계좌 사진에서 윗줄 평생계좌 번호에 아랫줄 「(계좌번호)」 꼬리표를 붙여 내 코드가 버렸다(후보 0).
     *    「정식 계좌번호를 택하라」 규칙도 안 먹었다. 새 형식 표본 = 평생계좌 1장(두 번호·이름표 모두) +
     *    자동차등록증 5장(소유자 5/5 정확, 계좌 오검출 0). registration_owner 는 **자동차등록증 소유자만**
     *    (종전 「사업자등록증 상호」까지 물어 운영 값에 딜러 상호가 섞였다) — 소유자 칸 후보로 쓴다.
     */
    public const PROMPT = <<<'TXT'
이 이미지에서 계좌번호와 자동차등록증 소유자를 찾아 JSON만 출력하라. 설명 금지.
형식:
{"doc_type":"",
 "accounts":[{"bank":"","number":"","holder":"","label":""}],
 "registration_owner":""}

규칙:
- accounts = 이미지에 보이는 **계좌번호를 하나도 빠뜨리지 말고 전부** 담아라. 한 칸에 번호가 두 줄이면 두 개로 나눠 담아라.
- number 에는 숫자와 하이픈만 넣어라. 괄호 속 설명은 label 로 옮겨라.
- label = 그 번호 옆에 적힌 이름표를 그대로 옮겨라(예: 계좌번호, 평생계좌, 차량대금, 매도비, 이전비).
- bank = 은행명, holder = 예금주. 이미지에 보이면 반드시 채워라.
- **「계좌」라고 적힌 번호는 전화번호처럼 보여도 계좌다**(031-296-5454 같은 평생계좌). 그대로 담아라.
- 명함·문의처의 연락처, 1566-/1588- 고객센터 번호, 금액(원), 차대번호, 문서번호는 계좌가 아니다.
- 계좌가 하나도 없으면 accounts 는 [].
- registration_owner = 자동차등록증의 「소유자 ⑨ 성명(명칭)」 칸에 적힌 이름. 자동차등록증이 아니면 "".
- doc_type = 이 이미지의 종류 하나(자동차등록증, 통장, 사업자등록증, 명함, 차량사진, 기타).
TXT;

    /**
     * 자동차등록증 2차 질의(2026-10-23) — 사진 전체(긴 변 1100px)로는 소유자 칸 글자가 작아 모델이 빈칸이나
     * 발급기관(「대구광역시」)을 냈다(ssancarboard 334소8009). **위쪽 45% 를 잘라 확대**하면 정확히 읽는다.
     * 등록증으로 판정된 사진에만 한 번 더 부른다(통장 위쪽을 자르면 예금주를 소유자로 낼 위험이 있다).
     */
    public const OWNER_PROMPT = <<<'TXT'
이 이미지는 자동차등록증의 윗부분이다. 「소유자 ⑨ 성명(명칭)」 칸에 적힌 이름을 괄호·글자 그대로 옮겨 JSON만 출력하라. 설명 금지.
발급기관(○○시 차량등록사업소)·사용본거지 주소는 소유자가 아니다. 안 보이면 "".
형식: {"registration_owner":""}
TXT;

    /** 등록증 2차 질의에서 자를 위쪽 비율 — 0.40 은 「상품용」 같은 꼬리를 잘랐다(실측). */
    public const OWNER_CROP_RATIO = 0.45;

    /** 모델이 낸 doc_type 이 자동차등록증인가. */
    public function isRegistration(string $docType): bool
    {
        return str_contains(str_replace(' ', '', $docType), '자동차등록증');
    }

    /** 2차 질의 응답 → 소유자명(발급기관을 낸 경우는 버린다). */
    public function parseOwner(string $raw): string
    {
        return $this->cleanOwner((string) (($this->decode($raw) ?? [])['registration_owner'] ?? ''));
    }

    /** 발급기관·관청을 소유자로 낸 경우 버린다 — 334소8009 에서 「대구광역시 차량등록사업소」가 실제로 나왔다. */
    private function cleanOwner(string $owner): string
    {
        $owner = trim($owner);
        if ($owner === '' || preg_match('/(등록사업소|차량등록|구청|시청|군청|도청)/u', $owner)
            || preg_match('/^\S+(특별시|광역시|특별자치시|특별자치도|도|시)$/u', $owner)) {
            return '';
        }

        return $owner;
    }

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
     * @return array{candidates: list<array>, rejected: list<array>, registration_owner: string, doc_type: string}
     */
    public function parseOne(string $raw, int $photoId): array
    {
        $data = $this->decode($raw);
        $out = ['candidates' => [], 'rejected' => [], 'registration_owner' => '', 'doc_type' => ''];
        if ($data === null) {
            return $out;
        }
        $out['registration_owner'] = $this->cleanOwner((string) ($data['registration_owner'] ?? ''));
        $out['doc_type'] = trim((string) ($data['doc_type'] ?? ''));

        // 새 형식 = accounts[](전부). 옛 형식(car_account/fee_account + found)도 읽는다 — 배포 직후 큐에 남은 응답·표본 대조용.
        if (is_array($data['accounts'] ?? null)) {
            $accounts = array_values(array_filter($data['accounts'], 'is_array'));
        } elseif (($data['found'] ?? false) === true) {
            $accounts = [];
            foreach (['car', 'fee'] as $role) {
                if (is_array($data[$role.'_account'] ?? null)) {
                    $accounts[] = $data[$role.'_account'];   // 매도비 판정은 그 칸의 label 로(종전과 같다)
                }
            }
        } else {
            return $out;
        }

        foreach ($accounts as $acc) {
            $number = trim((string) ($acc['number'] ?? ''));
            $label = trim((string) ($acc['label'] ?? ''));
            // 「010-2000-1111 (계좌번호)」 — 괄호 속 설명은 이름표로 옮기고 번호만 판정한다(2026-10-22 실측).
            if (preg_match_all('/\(([^)]*)\)/u', $number, $m)) {
                $label = trim($label.' '.implode(' ', array_map('trim', $m[1])));
                $number = trim((string) preg_replace('/\([^)]*\)/u', '', $number));
            }
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
                'role' => $this->hasFeeLabel($label) ? 'fee' : 'car',
                'bank' => $this->normalizeBank((string) ($acc['bank'] ?? '')),
                'number' => $number,
                'holder' => trim((string) ($acc['holder'] ?? '')),
                'label' => $label,
                'source_photo_ids' => [$photoId],
                'warnings' => $this->phoneLike($number) ? ['phone_like'] : [],
            ];
        }

        // 통장사본 한 장에 번호가 둘이면(평생계좌 + 계좌번호) 모델이 은행·예금주를 첫 줄에만 붙인다.
        // 그 사진에서 읽힌 은행·예금주가 **한 가지뿐일 때만** 빈 칸에 채운다 — 둘 이상이면 어느 계좌 것인지 모른다.
        foreach (['bank', 'holder'] as $f) {
            $vals = array_values(array_unique(array_filter(array_column($out['candidates'], $f))));
            if (count($vals) === 1) {
                foreach ($out['candidates'] as $k => $c) {
                    if ($c[$f] === '') {
                        $out['candidates'][$k][$f] = $vals[0];
                    }
                }
            }
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
        // 소유자 후보 — 사진마다 읽은 이름을 중복 없이(같은 차라면 보통 하나). 사람이 골라 소유자 칸에 넣는다.
        $owners = array_values(array_unique(array_filter(array_map(fn ($r) => $r['registration_owner'], $perPhoto))));
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
            'registration_owners' => $owners,
            'candidates' => array_values($candidates),
            'rejected' => $rejected,
        ];
    }

    /**
     * 계좌로 볼 수 없는 이유. null 이면 통과.
     *
     * 🚨 **0 으로 시작한다고 버리면 안 된다.** 처음엔 「0 으로 시작 = 전화번호」로 버렸는데,
     *    은행이 전화·휴대폰 번호로 만들어주는 **평생계좌**가 실제 송금 계좌다(IBK·농협 등).
     *    2026-09-10 운영에서 딜러가 보낸 「차대금 계좌 기업은행 031 296 5454」를 통째로 버려
     *    "계좌정보만 있는 사진인데 못 찾는다"가 됐다. ⇒ 버리지 말고 **경고를 달아 사람에게 보인다**
     *    (`phoneLike()` → warnings). 진짜 명함 전화번호는 모델이 애초에 계좌로 안 뽑는다.
     */
    private function rejectReason(string $number): ?string
    {
        if (preg_match('/[^0-9\-\s]/', $number)) {
            return 'not_numeric';
        }
        $d = $this->digits($number);
        // 고객센터 대표번호는 계좌가 될 수 없다(평생계좌로도 안 쓰인다).
        if (preg_match('/^1(566|588|544|577|599|899)/', $d)) {
            return 'call_center';
        }
        if (strlen($d) < 10 || strlen($d) > 17) {
            return 'bad_length';
        }

        return null;
    }

    /** 전화번호 형태(0 으로 시작 10~11자리) — 평생계좌일 수도, 잘못 읽은 전화번호일 수도 있다. */
    private function phoneLike(string $number): bool
    {
        $d = $this->digits($number);

        return str_starts_with($d, '0') && strlen($d) <= 11;
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
