<?php

namespace App\Services;

use App\Models\PurchaseListing;

/**
 * 여권·ID 카드 1장에서 **송금인 Name·문서번호 후보**를 만든다 (2026-10-29 ②, 기획서 plan-2026-11-weekend §②).
 *
 * 판독은 모델(`qwen2.5vl:7b`)이, 채택은 코드가(계좌추출 §14-15 와 같은 원칙). 모델엔 MRZ 를 글자 그대로 옮기게 하고
 * board 가 체크디지트로 검증한다 — 사전 실험(2026-10-28, 샘플 5장): 인쇄 칸은 5/5 값이 맞았지만 성·이름 뒤바뀜 1건,
 * MRZ 전사는 1/5 만 완전(누락·O/0 혼동)인데 **체크디지트가 전부 잡아냈다**. ⇒ MRZ(검증 통과분) 와 인쇄 칸을 **교차검증**:
 * 일치하면 후보 1개, 다르면 둘 다 후보(사람이 고른다). 자동 기입 없음.
 *
 * ID = **문서 번호**(여권 passport no. / ID 카드 card no.) — 개인번호·국적·생년월일은 반환하지 않는다(보관 금지, Jin 2026-10-28).
 * ID 카드 앞면엔 MRZ 가 없다 → 인쇄 칸이 주, MRZ(TD1 뒷면)는 보조.
 */
class PassportExtractor
{
    /**
     * 프롬프트 — 영문(라틴 문서). 🚫 이중 부정 금지(§14-15). 회전된 사진도 읽게 한 줄 넣되, 실패하면 Job 이 90° 씩 돌려 재시도한다.
     * ⚠️ 이 상수를 고치면 샘플(`Desktop\스크린샷\passport`, 레포 밖)로 재검증할 것 — 가짜 응답 테스트로는 이 계열 회귀가 안 잡힌다.
     */
    public const PROMPT = <<<'TXT'
This image is a passport data page or a national ID card (front or back). It may be rotated or photographed at an angle; read it anyway.
Output ONLY the following labeled lines, nothing else:
DOC_TYPE: PASSPORT or ID_CARD or OTHER
MRZ1: the first machine-readable line exactly as printed, character by character, keeping every '<' (leave empty if there is no MRZ)
MRZ2: the second machine-readable line exactly (leave empty if none)
MRZ3: the third machine-readable line exactly (ID cards only; leave empty if none)
SURNAME: the surname as printed in the visual zone
GIVEN: the given names as printed in the visual zone
DOC_NO: the passport number or the ID card number as printed (the document number, not the personal/national identification number)
TXT;

    /** 체크디지트 가중치 — ICAO 9303: 7,3,1 반복, A=10…Z=35, '<'=0. */
    private static function checkDigit(string $s): string
    {
        $sum = 0;
        $w = [7, 3, 1];
        foreach (str_split($s) as $i => $ch) {
            $v = ctype_digit($ch) ? (int) $ch : ($ch === '<' ? 0 : (ctype_alpha($ch) ? ord(strtoupper($ch)) - 55 : 0));
            $sum += $v * $w[$i % 3];
        }

        return (string) ($sum % 10);
    }

    private static function mrzClean(string $s): string
    {
        return (string) preg_replace('/[^A-Z0-9<]/', '', strtoupper($s));
    }

    /**
     * 모델 응답(라벨 줄) → 구조. 값은 정규화 전 원문에 가깝게 두고, 후보 생성(`candidates`)에서 정규화한다.
     *
     * @return array{doc_type: string, mrz: array, printed: array{surname: string, given: string, doc_no: string}}
     */
    public function parse(string $raw): array
    {
        $get = function (string $label) use ($raw): string {
            // ⚠️ 콜론 뒤는 [ 	]* — \s* 는 줄바꿈을 먹어 빈 칸(「SURNAME:」) 다음 줄 라벨을 값으로 잡는다.
            return preg_match('/^[ 	]*'.$label.'[ 	]*:[ 	]*(.*)$/mi', $raw, $m) ? trim($m[1]) : '';
        };
        $docType = strtoupper($get('DOC_TYPE'));
        $docType = str_contains($docType, 'PASS') ? 'PASSPORT' : (str_contains($docType, 'ID') ? 'ID_CARD' : ($docType === '' ? '' : 'OTHER'));
        $l1 = self::mrzClean($get('MRZ1'));
        $l2 = self::mrzClean($get('MRZ2'));
        $l3 = self::mrzClean($get('MRZ3'));

        $mrz = [];
        if ($l1 !== '' && $l2 !== '' && str_starts_with($l1, 'P') && strlen($l2) >= 40) {
            $mrz = $this->parseTd3($l1, $l2);
        } elseif ($l1 !== '' && $l2 !== '' && $l3 !== '' && strlen($l1) >= 28) {
            $mrz = $this->parseTd1($l1, $l2, $l3);
        }

        return [
            'doc_type' => $docType,
            'mrz' => $mrz,
            'printed' => ['surname' => $get('SURNAME'), 'given' => $get('GIVEN'), 'doc_no' => $get('DOC_NO')],
        ];
    }

    /** TD3(여권, 2×44) — 2행 = 문서번호(9)+cd · 국적 · 생년월일+cd · 성별 · 만기+cd · 선택(14)+cd · 종합 cd. 문서번호·종합만 쓴다. */
    private function parseTd3(string $l1, string $l2): array
    {
        $l2 = str_pad(substr($l2, 0, 44), 44, '<');
        $doc = substr($l2, 0, 9);
        $docOk = self::checkDigit($doc) === $l2[9];
        $compositeOk = self::checkDigit(substr($l2, 0, 10).substr($l2, 13, 7).substr($l2, 21, 22)) === $l2[43];
        [$sur, $giv] = $this->splitName(substr($l1, 5));

        return ['format' => 'TD3', 'surname' => $sur, 'given' => $giv, 'doc' => rtrim($doc, '<'), 'doc_ok' => $docOk, 'composite_ok' => $compositeOk];
    }

    /** TD1(ID 카드 뒷면, 3×30) — 1행 = 문서코드(2)+발급국(3)+문서번호(9)+cd … · 3행 = 성<<이름. */
    private function parseTd1(string $l1, string $l2, string $l3): array
    {
        $l1 = str_pad(substr($l1, 0, 30), 30, '<');
        $doc = substr($l1, 5, 9);
        $docOk = self::checkDigit($doc) === $l1[14];
        [$sur, $giv] = $this->splitName($l3);

        return ['format' => 'TD1', 'surname' => $sur, 'given' => $giv, 'doc' => rtrim($doc, '<'), 'doc_ok' => $docOk, 'composite_ok' => null];
    }

    /** `ERIKSSON<<ANNA<MARIA<<<<` → ['ERIKSSON', 'ANNA MARIA']. */
    private function splitName(string $body): array
    {
        [$sur, $giv] = array_pad(explode('<<', $body, 2), 2, '');

        return [trim(str_replace('<', ' ', $sur)), trim((string) preg_replace('/<+/', ' ', $giv))];
    }

    /**
     * 후보 — Name·ID 각각 `[{value, source}]`. source = both(MRZ 검증 통과 ∧ 인쇄 칸 일치) | mrz | printed.
     * MRZ 문서번호는 **체크디지트 통과분만** 후보(사전 실험: 틀린 전사는 전부 cd 에 걸렸다). MRZ 이름은 1행에 cd 가 없어 그대로 후보.
     * 인쇄 칸이 비면 MRZ 만, MRZ 가 없으면(ID 카드 앞면) 인쇄 칸만. 둘 다 없으면 빈 배열(= 못 읽음).
     *
     * @return array{names: list<array{value: string, source: string}>, ids: list<array{value: string, source: string}>, doc_type: string, warnings: list<string>}
     */
    public function candidates(array $parsed): array
    {
        $mrz = $parsed['mrz'] ?? [];
        $pr = $parsed['printed'] ?? [];
        $warnings = [];

        $mrzName = isset($mrz['surname']) ? PurchaseListing::normalizeRemitterName(trim($mrz['surname'].' '.$mrz['given'])) : null;
        $printedName = PurchaseListing::normalizeRemitterName(trim(($pr['surname'] ?? '').' '.($pr['given'] ?? '')));
        $printedName = $printedName !== null && preg_match('/[A-Z]{2,}/', $printedName) ? $printedName : null;

        $names = [];
        if ($mrzName !== null && $printedName !== null && $mrzName === $printedName) {
            $names[] = ['value' => $mrzName, 'source' => 'both'];
        } else {
            if ($mrzName !== null) {
                $names[] = ['value' => $mrzName, 'source' => 'mrz'];
            }
            if ($printedName !== null) {
                $names[] = ['value' => $printedName, 'source' => 'printed'];
            }
            if (count($names) === 2) {
                $warnings[] = 'name_mismatch';   // 성·이름 뒤바뀜(사전 실험 조지아 1장) — 사람이 고른다
            }
        }

        $mrzDoc = (! empty($mrz['doc_ok']) && ($mrz['doc'] ?? '') !== '') ? PurchaseListing::normalizeRemitterId($mrz['doc']) : null;
        if (isset($mrz['doc']) && $mrz['doc'] !== '' && empty($mrz['doc_ok'])) {
            $warnings[] = 'mrz_check_failed';   // 전사 오류(누락·O/0) — MRZ 번호는 버리고 인쇄 칸만 믿는다
        }
        $printedDoc = PurchaseListing::normalizeRemitterId($pr['doc_no'] ?? '');
        $printedDoc = $printedDoc !== null && strlen($printedDoc) >= 5 ? $printedDoc : null;

        $ids = [];
        if ($mrzDoc !== null && $printedDoc !== null && $mrzDoc === $printedDoc) {
            $ids[] = ['value' => $mrzDoc, 'source' => 'both'];
        } else {
            if ($mrzDoc !== null) {
                $ids[] = ['value' => $mrzDoc, 'source' => 'mrz'];
            }
            if ($printedDoc !== null) {
                $ids[] = ['value' => $printedDoc, 'source' => 'printed'];
            }
            if (count($ids) === 2) {
                $warnings[] = 'id_mismatch';   // 인쇄 칸에서 글자 하나 빠뜨린 사례(코소보 1장) — MRZ 쪽이 맞을 확률이 높다
            }
        }

        return ['names' => $names, 'ids' => $ids, 'doc_type' => (string) ($parsed['doc_type'] ?? ''), 'warnings' => $warnings];
    }
}
