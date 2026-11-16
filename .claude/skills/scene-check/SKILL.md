---
name: scene-check
description: board 주간 「씬 점검(화면 회귀)」. 업무 가이드 씬을 로컬 8003 에서 끝까지 눌러 가이드가 인용한 화면 문구·DB 값이 맞는지 본다. 손으로 돌릴 때("씬 점검 돌려줘", "/scene-check"), 특정 씬만("manage-edit 씬만"), 실패를 볼 때("씬 점검 실패 봐줘") 사용. board 전용 · 🚫 배포 게이트 아님.
disable-model-invocation: true
---

# board 씬 점검 (2026-11-16 Jin — 「board 는 board 에 맞게」)

정본 = `meetings/plan-scene-walkthrough-2026-11.md` §7-7 끝 · 코드 = `scripts/guide-shots/check/`.
car-erp 씬 점검과 같은 생각(화면 문구·DB 값 확인, 주 1회, 배포 게이트 아님)이지만 파일은 board 것이다 — 🚫 car-erp 파일을 복사하지 않는다.

## 🚫 지킬 것
- 로컬 8003 은 Jin 이 띄운다 — 🚫 `php artisan serve` 를 띄우지 않는다. 안 떠 있으면 Jin 에게 말한다.
- 사전 확인(`check/preflight.php`)이 알림톡 마스터 스위치를 본다 — 켜져 있으면 멈춘다. 우회하지 말 것.
- 로컬 DB 만 바꾼다. 되돌리기 = `check/reset.php`(차량번호·이메일로 찾아 되돌리기만, 예시가 없으면 만들지 않고 멈춤). 🚫 `migrate:fresh`·`db:wipe`.
  `boot.php` 가 로컬·board DB 가 아니면 멈춘다(이 폴더는 master 로도 간다).
- 운영 서버 접속 없음. 결과에 화면 텍스트를 통째로 붙이지 않는다(로컬 DB 에 실명·개인 이메일 행이 섞여 있다).
- 1단계 범위 = board 안에서 끝나는 씬만. **ERP 로 보내는 버튼(입금요청·구매확정 성공·선적요청·서명·클레임 ERP 로 보내기·탁송 매입잔금)·엔카 추출·여권 읽기·이미지 정보 찾기는 누르지 않는다**(로컬 ERP 데이터를 되돌릴 수 없고, ERP 가 알림톡을 보낼 수 있다).

## 1. 돌리기
```bash
cd /c/xampp/htdocs/board/scripts/guide-shots && node check/run.mjs              # 전부(약 2분 — 사전 확인 → 되돌리기 → 씬 순서대로)
node check/run.mjs manage-edit audit-find                                       # 골라서(순서는 SCENES 순 — audit-find 는 manage-edit 기록을 본다)
```
결과 = 화면 표 + `out/check/result.json` + 마지막 줄 `SUMMARY 통과 N / 실패 M`. 멈춘 씬은 `out/check/<slug>-error.png`.

## 2. 실패를 가른다
| 분류 | 판단 | 할 일 |
|---|---|---|
| **버그** | 화면 동작·저장 값이 가이드·의도와 다르다 | 코드 수정(가이드 그대로) → 다시 돌림 |
| **의도한 변경** | 최근 커밋이 화면 문구·동작을 바꿨다(`git log -- resources/views lang` 로 확인) | 점검 기대값 고침 → 재실행 → **같은 문구를 쓰는 가이드 씬(`resources/guides/<slug>/`)도 고친다** |
| **스크립트 문제** | 화면은 맞는데 점검이 못 찾음(선택자·대기 시간·되돌리기 누락) | `check/scenes/<slug>.json`·`reset.php` 고침 |

보고는 「씬 · 몇 단계 · 무엇이 없음 · 분류 · 할 일」 한 줄씩. 고치기 전에 Jin 에게 분류를 알린다(코드 수정은 dev 까지, 배포는 Jin 지시).

## 3. 씬 추가
`check/scenes/<slug>.json` — 조작 단계는 캡처 도구(`plans/*.json`)와 같은 이름(goto·click·clickText·set·select·autoConfirm·wait), 확인 단계는 `{"check": 이름, "text"|"noText"|"db"}`.
- 기대 문구는 **가이드 본문이 「」·[ ] 로 인용한 화면 문구**에서 가져온다(가이드와 화면이 어긋나면 잡히도록).
- 저장·삭제처럼 바꾸는 단계 뒤에는 DB 확인을 붙인다(화면 안내만 보면 조용히 안 된 것을 놓친다 — 캡처 때 실제로 겪음).
- 씬이 가짜 데이터를 바꾸면 `reset.php` 에 되돌리기를 넣는다. 그 다음 `SCENES` 배열에 순서대로 추가.
- 한글 입력은 `set`(키보드 입력은 글자가 빠진다) · 확인 창은 `autoConfirm` · 주소는 `localhost:8003`(127.0.0.1 이면 Livewire 가 죽는다).
