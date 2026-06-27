# 인계 — 인스턴스 명명 규칙 통일 (car-erp 세션에 전달)

> 작성 2026-06-27 (board 세션). board CLAUDE.md 에는 이미 박제함. **car-erp 쪽도 동일하게 car-erp `CLAUDE.md` 에 박제**해 달라(메모리는 레포·PC별이라 안 따라옴 — git 커밋된 파일만 전파).

## 규칙 (Jin 확정 2026-06-27)
회사 3사 = **ssancar / heyman / karaba**(karaba board 는 추후 설치). 각 회사마다 erp·board 한 쌍.

대화·문서에서 인스턴스를 부를 때 **`회사+앱` 한 단어, 소문자, 하이픈·공백 없이**:

| | erp | board |
|---|---|---|
| ssancar | **ssancarerp** | **ssancarboard** |
| heyman | **heymanerp** | **heymanboard** |
| karaba | **karabaerp** | karababoard (board 추후) |

- "ssancar-erp" / "ssancar erp" / "HeymanBoard" 식 표기 ❌ → ssancarerp · heymanboard ✅
- 앱이 문맥상 분명하면 회사명만(ssancar/heyman/karaba)으로 짧게 불러도 됨.
- **코드 경로·repo 이름**(`car-erp`, `/var/www/board-ssancar` 등)은 기존 그대로 — 이 규칙은 *대화·문서에서 인스턴스를 가리키는 호칭* 통일용이지 파일/디렉터리 리네임이 아님.

## car-erp 세션이 할 일
car-erp `CLAUDE.md` 의 멀티 인스턴스/용어 부분에 위 표를 한 단락으로 추가 커밋. (board 와 같은 문구.)
