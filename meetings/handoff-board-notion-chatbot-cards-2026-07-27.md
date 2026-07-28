# BOARD Notion 챗봇 기능 카드 발행 인계

> 작업일: 2026-07-27
> 대상: `board`
> 목적: ERP와 같은 H2 카드형 지식 구조를 BOARD에도 추가해 로컬 챗봇이 기능별로 정확히 검색하도록 구성

## 1. 완료 상태

- Notion `사내 업무 가이드 → 🛒 매입보드 (BOARD)` 아래에
  `📇 기능 카드 (챗봇용)`을 **새 하위 트리로 발행 완료**.
- 기존 BOARD 가이드 5개와 허브 네비게이션은 수정·삭제하지 않음.
- 그룹 6개, 카드 37장, 총 236블록(부모 소개 1블록 포함).
- 로컬 챗봇 색인 재생성 완료.
- 운영 서버에는 색인을 전송하지 않음.

## 2. 로컬 소스

| 파일 | 역할 |
|---|---|
| `scripts/notion-cards/cards.json` | 그룹·카드 제목과 `질문 예시/어디/무엇/적는 것/누가/반영처` 원고 |
| `scripts/notion-cards/publish.php` | Notion dry-run·신규 발행기 |

발행기는 BOARD 섹션에 동일 제목의 카드 부모가 이미 있으면 기존 내용을 보존하기 위해
중단한다. 기존 페이지를 아카이브하거나 덮어쓰는 `--force` 동작은 넣지 않았다.

## 3. Notion 위치와 페이지 ID

부모:

- `🛒 매입보드 (BOARD)`: `37345d82-bd83-8150-beec-e006cb5b36e1`
- `📇 기능 카드 (챗봇용)`: `3aa45d82-bd83-814f-9ae6-c38058e41d84`

그룹:

| 그룹 | 카드 | 블록 | Notion page ID |
|---|---:|---:|---|
| A. 전체 흐름·찾아가기 | 4 | 23 | `3aa45d82-bd83-81fa-87c1-c83b6e5a0f71` |
| B. 영업·매입 진행 | 10 | 67 | `3aa45d82-bd83-8188-9716-f909a5772be3` |
| C. 현지확인·알림 | 4 | 24 | `3aa45d82-bd83-811d-80d7-dac54b32aec4` |
| D. 내 정산·미수·선적 포털 | 5 | 34 | `3aa45d82-bd83-8145-b214-e854e713d490` |
| E. 관리·시스템 | 5 | 33 | `3aa45d82-bd83-8105-bc50-caa6bbf84e8d` |
| F. 오류·잠금·개념 | 9 | 54 | `3aa45d82-bd83-81f4-9f57-dcbae9baeaad` |

## 4. 실행 기록

토큰은 현재 Process 값을 우선 사용하고, 없을 때만 User 환경변수를 주입한다.

```powershell
if (-not $env:NOTION_TOKEN) {
    $env:NOTION_TOKEN = [Environment]::GetEnvironmentVariable('NOTION_TOKEN', 'User')
}
cd C:\xampp\htdocs\board
php scripts/notion-cards/publish.php
php scripts/notion-cards/publish.php --apply
```

결과:

```text
DRY-RUN: 그룹 6개 · 카드 37장 · 쓰기 없음
APPLY: 부모 1개 · 그룹 페이지 6개 · 카드 37장 발행 성공
```

## 5. 최종 검증

- 기존 BOARD 페이지 ID 유지:
  - 전체 워크플로우: `39345d82-bd83-81ba-bc8a-c0747bbc64fa`
  - 영업: `39345d82-bd83-810f-8697-dc29c3df372c`
  - 검차: `39345d82-bd83-81d2-838d-e696333b5840`
  - 관리: `39345d82-bd83-8192-a85f-ee6eee935ca2`
  - 에러·락 대처: `39345d82-bd83-812c-b121-c42f9d19e23f`
- 기존 허브 네비게이션 5개 링크 모두 정상.
- 카드 제목 37개 모두 `index-board.json`에서 확인.
- BOARD 색인: 44청크 → 82청크.
- 카드 트리 색인: 부모 소개 1 + 카드 37 = 38청크.
- BOARD 색인의 ERP 청크 혼입: 0.
- ERP 색인: 101청크 유지.
- ERP 색인 SHA-256:
  `2666A0903FEA8BD0266899E2B0AF9588AE6BCC8EE01B1BBA552C6C7B9962D9A6`
  (작업 전후 동일).
- PHP 구문 검사와 JSON 구조·중복 제목·Notion 100블록 한도 검사 통과.

로컬 색인 위치:

```text
C:\Users\User\llm-poc\index-board.json
C:\Users\User\llm-poc\index-erp.json
C:\Users\User\llm-poc\index.json
```

## 6. 이후 카드 수정 시 주의

1. `cards.json`을 먼저 수정하고 카드 수·중복 제목·문단 길이를 검증한다.
2. 기존 Notion 카드 트리는 사용자 메모 보존 때문에 현재 발행기로 덮어쓰지 않는다.
3. 갱신이 필요하면 기존 페이지의 정확한 그룹 ID와 H2 범위를 읽어
   **바뀐 카드만 수정하는 별도 패치 스크립트**를 만든다.
4. 페이지 삭제·아카이브·재생성은 네비게이션과 메모 손실 위험 때문에 금지한다.
5. Notion 반영 후 `C:\Users\User\llm-poc\sync.php`로 로컬 색인을 재생성한다.
6. 운영 서버 전송은 별도 요청과 승인 없이 실행하지 않는다.

## 7. 이번 작업에서 제외한 것

- 기존 BOARD 업무 가이드 본문 변경
- 허브 네비게이션에 카드 페이지 링크 추가
- BOARD 앱에 챗봇 위젯 구현
- 개발현황 페이지 수정
- 운영 서버 색인 배포
