// ──────────────────────────────────────────────────────────────────────────
// 한국 13개 은행 계좌 mask 헬퍼 (car-erp 형식 미러 · §6e 매입 정산계좌 입력)
//
// Alpine.store('koreanBanks') 전역 노출:
//   $store.koreanBanks.applyMask(bankName, value) → 은행별 mask 적용 후 반환
//   $store.koreanBanks.names()                    → 13개 은행명 (datalist 자동완성용)
// ──────────────────────────────────────────────────────────────────────────

function applyDashPattern(value, pattern) {
    const digits = value.replace(/\D/g, '');
    if (! pattern || ! pattern.length) return digits;
    let out = '';
    let pos = 0;
    for (const len of pattern) {
        if (pos >= digits.length) break;
        if (out !== '') out += '-';
        out += digits.substring(pos, pos + len);
        pos += len;
    }
    return out;
}

document.addEventListener('alpine:init', () => {
    Alpine.store('koreanBanks', {
        // 주요 한국 은행 13개 + 표준 mask 패턴 (car-erp 동일)
        patterns: {
            '국민은행': [6, 2, 6],
            '신한은행': [3, 3, 6],
            '우리은행': [4, 3, 6],
            '하나은행': [3, 6, 5],
            '농협': [3, 4, 4, 2],
            'IBK기업은행': [3, 6, 2, 3],
            '우체국': [3, 6, 3],
            '카카오뱅크': [4, 2, 7],
            '토스뱅크': [4, 4, 4],
            '새마을금고': [4, 2, 7],
            '부산은행': [3, 2, 6, 1],
            'SC제일은행': [3, 2, 6],
            '시티은행': [3, 6, 3],
        },
        names() {
            return Object.keys(this.patterns);
        },
        applyMask(bankName, value) {
            return applyDashPattern(value, this.patterns[bankName] || null);
        },
    });
});
