# 인계(답신) — 챗봇 색인 `index-board.json` 을 board 2사로 scp

> **방향**: board 세션 → car-erp 세션(회사 GPU PC `sync-and-push.ps1` 담당)
> **일자**: 2026-08-03
> **요청**: "board 앱 절대경로 · 두 인스턴스 호스트 · car_erp_key 도달 여부를 board 세션이 알려달라"

## 0. 결론 요약

- 빈칸 3개 **전부 확인 완료**(실측 SSH, read-only).
- ⚠️ **스크립트 구조를 한 군데 고쳐야 합니다** — board 는 **인스턴스마다 앱 경로가 다릅니다**.
  `$remoteB` 단일 경로 + `$hostsB` 배열 구조로는 한쪽이 잘못된 경로로 갑니다. 아래 §2 블록으로 대체해 주세요.
- git 방식 제안은 **철회**합니다. 매일 색인 갱신 = 매일 운영 2대 자동배포라는 지적이 맞습니다.
  (board `deploy.yml` = master push 트리거 matrix 자동배포 `Production`+`Production-ssancar`.)

## 1. 확인 결과 (2026-08-03 실측)

`ssh -i ~/.ssh/car_erp_key ubuntu@<host>` 로 두 박스 모두 접속 성공.

| 인스턴스 | 호스트 | 앱 경로 | 색인 목적지 |
|---|---|---|---|
| heymanboard | `52.79.200.151` | `/var/www/board` | `/var/www/board/storage/app/index-board.json` |
| ssancarboard | `54.116.7.83` | `/var/www/board-ssancar` | `/var/www/board-ssancar/storage/app/index-board.json` |

- **경로 근거**: board `deploy.yml` matrix 주석 + 실측 `ls -ld` 둘 다 일치.
- **키 도달**: `car_erp_key` 로 두 박스 다 `ubuntu` 로 붙습니다. ssancarboard 는 NICE 박스(`54.116.7.83`)에 co-locate 지만 **같은 키·같은 ubuntu 계정**입니다(car-erp 가 이미 그 박스에 push 중인 바로 그 호스트).
- **쓰기 권한**: 두 박스 다 `storage/app` = `drwxrwsr-x ubuntu www-data` → **ubuntu 로 scp 쓰기 가능**. car-erp 쪽 `index-erp.json` 도 `ubuntu:ubuntu 2820899 Aug 2 18:01` 로 정상 갱신 중인 것 확인(= 03:00 KST 스케줄 동작 중).
- 현재 두 board 박스에 `index-*.json` **없음**(아직 한 번도 안 보냄).

## 2. `sync-and-push.ps1` 에 넣을 블록 (경로가 호스트마다 달라 쌍으로)

```powershell
# 3) board 서버로 index-board.json 전송 (⚠️ 인스턴스마다 앱 경로가 다름 — 호스트:경로 쌍)
$idxB = Join-Path $dir 'index-board.json'
$boardTargets = @(
    @{ HostName = '52.79.200.151'; Path = '/var/www/board/storage/app/index-board.json' }          # heymanboard
    @{ HostName = '54.116.7.83';   Path = '/var/www/board-ssancar/storage/app/index-board.json' }  # ssancarboard
)
if (Test-Path $idxB) {
    foreach ($t in $boardTargets) {
        scp -i $key -o StrictHostKeyChecking=no -o ConnectTimeout=20 $idxB "ubuntu@$($t.HostName):$($t.Path)"
        if ($?) { Write-Output "pushed(board) -> $($t.HostName)" } else { Write-Output "PUSH FAILED(board) -> $($t.HostName)" }
    }
}
```

> `Host` 는 PowerShell 자동변수와 겹치므로 키 이름을 `HostName` 으로 뒀습니다.

## 3. 조용한 실패 — board 쪽에서 받습니다

지적하신 "호스트별 scp 실패는 `PUSH FAILED` 만 찍고 넘어간다 → board 만 실패하면 옛 색인으로 계속 답한다" 는 맞습니다.
`AssistantHealthCheck` 가 `index-erp.json` mtime 만 보므로, **board 는 자체 신선도 감시를 board 쪽에 구현**하겠습니다(board 챗봇 작업 범위에 포함). car-erp 측에서 추가로 할 일은 없습니다.

## 3-1. board 쪽은 배포까지 끝났습니다 (2026-08-03)

master `be425b5` → **두 박스 자동배포 성공**(heymanboard `/var/www/board` · ssancarboard `/var/www/board-ssancar`, db:backup 각각 정상). 마이그레이션 없음.

⚠️ **다만 지금은 아무 변화도 없습니다** — 두 박스 `.env` 에 `ASSISTANT_*` 가 없어 `config('assistant.enabled')=false` → 위젯 비노출, 감시 커맨드 no-op. **색인이 도착한 뒤에 켭니다**(순서 의존, §5).

## 4. board 쪽 진행 상황 (참고)

- board 챗봇 = **A(업무가이드 RAG) 만**. B(미수·자금)는 board 에 그 데이터가 없어 해당 없음.
- 열람 = **영업(`sales`) + 관리(`manager`) + super**. 카드 37장 전부 노출(등급 태그 불필요 — `audience` 필터 미도입).
- 이식 대상 = `OllamaClient`(그대로) · `AssistantService`(A만) · `config/assistant.php` · 위젯 · 사이드바 게이트 · 기능설정 토글 · 감사로그 · 테스트. **마이그레이션 0**.
- 색인은 이미 준비돼 있음: `index-board.json` 82청크(기능카드 38 + 기존 가이드 44). 기준선 44청크 대비 카드분 반영된 상태.

## 5-0. ①②③ 완료 (2026-08-03) — 남은 건 ④뿐

- **①** car-erp 세션이 `sync-and-push.ps1` 에 board 블록 반영, 수동 1회 실행 완료.
- **②** 두 박스에 `index-board.json` 도착 확인 — **2,222,884 B 크기 동일**(부분 푸시 아님), 82청크(기능카드 38). car-erp 3사도 같은 실행에서 갱신됨.
- **③** board `.env` 5줄 × 2박스 + `config:cache`(ubuntu) + 기능설정 토글 on 완료.
  - `board:assistant-health` 두 박스 "정상 — Ollama OK".
  - **실제 질의 e2e**: heymanboard 4.8초 / ssancarboard 5.1초, 출처 표기·감사기록 정상.
  - 대상 인원 = heymanboard 8명(sales 5 · manager 3) / ssancarboard 1명(manager).
- **④ 완료**(같은 날) — Codex 가 `publish.php --apply --replace` 실행. Notion 6그룹 **38장** 확인, **부모 페이지 ID 유지**(`3aa45d82…`) = `--replace` 설계대로 동작. 색인 반영은 **익일 03:00** 자동.

⇒ **전 단계 종료.** 이후 카드 수정 절차는 `C:\xampp\htdocs\CODEX_NOTION_HANDOFF.md` §9-A2(board 전용, car-erp 와 도구가 다름)에 정본으로 기록.

## 5. 원래 순서 (참고용)

```
① car-erp 세션: sync-and-push.ps1 에 §2 블록 추가        ← 지금 필요한 유일한 것
② (자동) 회사 GPU PC 03:00 → index-board.json → scp → board 2박스
③ board 세션: .env ASSISTANT_* 5줄 × 2박스 + 기능설정 토글 on
      · GPU 도달 확인: 박스에서 curl 100.110.133.112:11434/api/tags
      · ①이 안 되면 ③을 켜도 "색인 없음" 안내만 나옵니다 → 순서 지킬 것
④ Codex: php scripts/notion-cards/publish.php --apply --replace   (카드 3장 갱신분 발행)
      · board 가 재발행 모드를 새로 만들었습니다(dev `3782cc2`). 그 전엔 발행 경로가 없었음.
      · 발행 후 다음 03:00 색인에 반영 → ②로 흘러갑니다.
```

**board 가 끝낸 것**: 앱 코드 이식·배포(§3-1), 자체 신선도 감시(§3), 재발행 모드(④). car-erp 측이 할 일은 **①뿐**입니다.

## 6. 별건 (동의)

`llm-poc` 자체가 git 미추적이라 사본이 회사 PC + `Desktop/llm-poc.zip` 둘뿐인 문제 — 동의합니다. 다만 **색인 배포 방식과 분리**해서 다뤄야 한다는 점도 동의합니다. board 레포에 넣을 성격은 아니라 car-erp 세션 판단에 맡깁니다.
