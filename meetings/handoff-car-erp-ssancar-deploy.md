# 인수인계 — car-erp ssancar 인스턴스 배포 (heyman 쌍 복제)

> **왜 이 문서가 있나**: Claude 세션끼리는 실시간 통신 채널이 없다(컨텍스트·메모리 격리). board↔car-erp 협업의 유일한 다리 = git 커밋된 파일 + 사용자(Jin)가 옮기는 인계 문서. board 세션이 만든 이 노트를 Jin 이 car-erp 세션에 전달한다.
>
> 작성 2026-06-26 (board 세션). **새 코드 작업 아님** — heyman 에서 이미 운영 LIVE 인 car-erp + board 한 쌍을 ssancar 용으로 그대로 복제 배포하는 일이다.
>
> 🔧 **UPDATE 2026-06-26 (car-erp 세션 회신 — 토폴로지 정정)**: ssancar 쌍은 **새 별도 인스턴스가 아니라 기존 NICE 박스 `54.116.7.83`(현재 Django 구동)에 co-locate** 한다. 이유 = NICE 화이트리스트 IP 고정. Django 와 공존 후 정리는 추후. → 아래 §6-1 "새 인스턴스 프로비저닝"은 **"기존 박스에 vhost·DB·워커 추가"** 로 대체(박스에 nginx/php-fpm/mysql 이미 있을 수 있음 — car-erp ssancar 와 공유). **도메인 확정 (Option B)**: car-erp = apex `heymancar.com`, board = `board.heymancar.com`. → board `CAR_ERP_BASE_URL=https://heymancar.com`, `APP_URL=https://board.heymancar.com`. NICE `/provide/` 는 apex 블록의 location 으로 Django 와 공존 — 이식 때 그 location 만 car-erp 로 flip(heyman .env 불변). 이번 범위 = **쌍 배포만**(NICE 게이트웨이 이식 제외). 다운타임 = 주말이라 OK. 공유 시크릿 2개는 board 세션이 생성 완료(별도 전달). **실행 = 주말, 지금은 계획·준비만.**

---

## 0. 한 줄 요약 (car-erp 세션이 알아야 할 것)
heyman 에서 돌고 있는 car-erp 를 **ssancar 전용 별도 Lightsail 인스턴스**에 **새 DB·새 APP_KEY** 로 한 벌 더 띄운다. 코드는 동일(현재 master). **heyman 의 DB·APP_KEY·시크릿은 절대 재사용 금지** — 테넌트(회사)별 완전 격리. 연동(board↔car-erp)은 ssancar 쌍 안에서만 짝짓는다(heyman 과 교차 금지).

## 1. 토폴로지 (Jin 확정 2026-06-26)
```
[heyman 서버] car-erp(heyman) ──B/portal/attach── board(heyman)     ← 기존, 건드리지 않음
[ssancar 서버(신규)] car-erp(ssancar) ──B/portal/attach── board(ssancar)  ← 이번에 새로
```
- ssancar = **새 별도 인스턴스**(heyman 과 서버·DB·php-fpm 완전 분리).
- 연동 범위 = **heyman 과 동일 전부**: 연동 B(board→car-erp 매입전환) + 영업포털 read API + 차량첨부 v2 + 금액매핑 v3 + 연동 A(respond.io).
- ssancar car-erp 가 가져야 할 수신측 = 이미 master 에 다 있음(아래 2번 버전 확인만).

## 2. car-erp 배포 전 버전 확인 (이미 구현된 것들 — 누락 없는지만)
ssancar car-erp 가 받는 board 트래픽을 다 처리하려면 아래가 master 에 포함돼 있어야 한다(전부 heyman 에서 이미 LIVE):
- [ ] `POST /api/internal/purchase-sync` 수신측 (연동 B, HMAC) — 멱등키 = **vehicle_number**(VIN 아님), 영업매칭 = salesman_email. 권위 = `docs/integration/purchase-sync-receiver.md`.
- [ ] 금액매핑 **v3** 수신 (매입/판매 금액분해·운임비 판매통화 환산·바이어/컨사이니). 권위 = car-erp 측 amount-mapping 수신 문서.
- [ ] 차량첨부 **v2** 수신 (`attachments[]` → 차량 첨부탭, 서버사이드 S3 copy). 권위 = `meetings/handoff-car-erp-vehicle-attachments.md`(board측).
- [ ] 영업포털 **read API** (board 가 car-erp 를 GET 으로 읽음, 별도 read HMAC). 권위 = car-erp `board-portal-api.md`.
→ heyman master 와 같은 커밋이면 다 들어있다. ssancar 는 **같은 master 를 새 서버에 clone** 하면 끝(코드 수정 0).

## 3. ssancar 쌍을 잇는 "계약" — 양쪽 .env 가 맞아야 하는 값
board(ssancar) 와 car-erp(ssancar) 가 서로를 가리키게 하는 연결고리. **heyman 값 복사 금지 — ssancar 전용으로 새로 생성.**

| 항목 | board(ssancar) .env | car-erp(ssancar) .env | 규칙 |
|---|---|---|---|
| 매입전환 시크릿 | `CAR_ERP_HMAC_SECRET` | (purchase-sync 검증 시크릿) | **동일 값**. `openssl rand -hex 32` 새로 1개 |
| 포털 read 시크릿 | `CAR_ERP_READ_HMAC_SECRET` | (board-portal read 검증 시크릿) | **동일 값**. `openssl rand -hex 32` 새로 1개 |
| car-erp 주소 | `CAR_ERP_BASE_URL=https://<ssancar car-erp 도메인>` | — | board 만 보유(car-erp 는 board 를 호출 안 함, 응답만) |
| 영업 매칭 | (board /users 영업 이메일) | `salesmen.email` | board 영업 **로그인 이메일 = car-erp salesmen.email** 이어야 연동 B 가 담당자 자동지정. 다르면 board `/users` 의 car-erp 이메일 오버라이드로 맞춤 |

- 시크릿 2개는 **ssancar 새로 생성** → 양쪽 .env 에 똑같이. board 쪽 세팅은 board 세션이 함. car-erp 세션은 car-erp .env 쪽만.
- car-erp 가 board 를 역호출하는 경로는 없음(연동 B·포털 모두 board→car-erp 단방향). 따라서 car-erp .env 에 board URL 불필요.

## 4. car-erp 인스턴스 자체 비밀(테넌트별 새로) — car-erp 세션의 평소 런북대로
ssancar car-erp 는 heyman 과 **완전 별도 테넌트**이므로 아래는 전부 ssancar 전용 신규:
- **APP_KEY** (heyman 과 다름. RRN 등 암호화 키 — 생성 후 백업, 변경 금지)
- **DB** (ssancar 전용 DB + 전용 user)
- **NICE** 차량/명의조회 키 (ssancar 계약분이 있으면 그것, 없으면 Jin 확인)
- **respond.io** ssancar 워크스페이스 토큰/시크릿 (연동 C: car-erp→respond.io. heyman 워크스페이스와 분리)
- **S3** 버킷/prefix (heyman 과 같은 버킷 prefix 분리 or 별도 버킷 — Jin 확인)
- 도메인 + HTTPS(certbot), 큐 워커(supervisor), 스케줄러(cron) — car-erp 런북대로.

## 5. car-erp 세션 프롬프트 (그대로 붙여넣기)
```
ssancar 전용 새 Lightsail 인스턴스에 car-erp 를 한 벌 더 배포하려고 해.
새 코드 아님 — 현재 master 를 새 서버에 clone 해서 heyman 과 완전 별도 테넌트로
띄우는 배포 작업이야. heyman 의 DB/APP_KEY/시크릿은 절대 재사용하지 마.

요청:
1. 이 인스턴스가 board 트래픽을 다 받을 수 있게 master 에 아래가 있는지 확인:
   - POST /api/internal/purchase-sync (연동 B, 멱등키=vehicle_number, 영업매칭=salesman_email)
   - 금액매핑 v3 수신 / 차량첨부 v2(attachments[] 서버사이드 S3 copy) / 영업포털 read API
   (전부 heyman 에서 이미 LIVE 면 같은 master 라 들어있음. 누락만 알려줘.)
2. ssancar 전용 신규로 세팅할 것: 새 APP_KEY(RRN 암호화, 백업), 새 DB+전용 user,
   ssancar NICE 키, ssancar respond.io 토큰(연동 C), S3 prefix, 도메인+HTTPS,
   큐 워커, 스케줄러. car-erp 배포 런북대로.
3. board(ssancar) 와 잇는 공유 시크릿 2개 — board 세션이 openssl 로 새로 생성해서
   줄 거야. 받으면 car-erp .env 에 그대로:
   - purchase-sync 검증 시크릿  (board CAR_ERP_HMAC_SECRET 과 동일 값)
   - board-portal read 검증 시크릿 (board CAR_ERP_READ_HMAC_SECRET 과 동일 값)
4. 영업담당자 자동지정: salesmen.email 이 board(ssancar) 영업 로그인 이메일과
   일치해야 연동 B 가 담당자를 자동으로 잡는다. ssancar 영업진 이메일로 salesmen 시드.
5. heyman 인스턴스/DB 는 절대 건드리지 마. 배포 전 cwd/대상 DB 확인.

확인 후 ssancar car-erp 도메인을 알려줘 — board 의 CAR_ERP_BASE_URL 에 넣을 거야.
```

## 6. 배포 순서 (한 쌍 부트스트랩)
> car-erp(수신측·포털 응답측)가 먼저 살아 있어야 board 의 연동 B/포털이 의미가 있다. **car-erp 먼저, board 나중.**
1. **새 Lightsail 인스턴스 프로비저닝** (Ubuntu + PHP 8.4(+확장) + nginx + MySQL + supervisor + certbot + composer + node). 두 앱 공용 인프라.
2. **공유 시크릿 2개 생성** (`openssl rand -hex 32` ×2) — 안전한 곳에 보관. (board 세션이 생성·전달)
3. **car-erp(ssancar) 배포** → 도메인 + HTTPS up → ssancar car-erp 도메인 확정.
4. **board(ssancar) 배포** — board `meetings/deploy-runbook.md` §B 그대로(단 새 서버 IP/도메인/DB). `.env` 에 새 APP_KEY + 공유 시크릿 2개 + `CAR_ERP_BASE_URL=https://<ssancar car-erp 도메인>` + ssancar respond.io 토큰 + S3.
5. **영업 이메일 매핑** (board `/users` ↔ car-erp salesmen.email) 확인.
6. **respond.io** ssancar 워크스페이스 연결 (연동 A: webhook secret/토큰).
7. **e2e**: board(ssancar) 에서 차 1대 won → car-erp(ssancar) 자동 생성 확인 + board `/audit` integration_events 201.

## 7. 함정 (heyman 운영서 실제 겪은 것)
- **큐 워커 FATAL**: MySQL 블립에 워커가 `startretries` 초과로 죽어 방치되면 연동 B·synced 토스트 통째 멈춤. supervisor conf 에 `startretries=20`+`startsecs=5` 넣을 것(deploy-runbook B-6).
- **APP_KEY 분실 = 복호화 불가**: car-erp=RRN, board=payee_account. 둘 다 생성 즉시 백업.
- **cwd/대상 DB 사고**: heyman·ssancar(·board) 형제 디렉터리 + 별도 DB. artisan/migrate 전 `\DB::connection()->getDatabaseName()` 로 대상 확인.
- **GitHub Actions 자동배포는 인스턴스당 환경 1개** — 한 repo 가 두 서버에 자동배포하려면 deploy.yml 에 ssancar 용 두 번째 job/environment 추가 필요(아래 board 세션 메모 참조). 그전까지 ssancar 는 수동 배포(서버에서 git pull).

---

## board 세션 측 — 준비 상태 + 주말 런시트

### 준비 완료 (2026-06-26, 계획 단계)
- ✅ 공유 시크릿 2개 생성 (`openssl rand -hex 32`) — scratchpad `ssancar-deploy-secrets.txt`. **.env 전용, git 금지.**
- ✅ board(ssancar) .env 템플릿 작성 — 도메인 확정값 반영(`CAR_ERP_BASE_URL=https://heymancar.com`, `APP_URL=https://board.heymancar.com`).
- ✅ 회사분리 = 코드 0 확인 — 브랜딩은 `settings.sidebar_brand` 행(master 에 이미 있음, 기본 'HeymanBoard'). migrate 후 super 가 화면에서 변경.
- ✅ S3 확정 — board(ssancar)는 car-erp 와 **같은 버킷 `ssancar-erp-docs` + 같은 키 `ssancar-erp-s3-user`**(버킷 전체 권한). board 는 `purchase-board/...` prefix 로 업로드 → car-erp 같은버킷 복사(첨부 v2). heyman 의 `heysellcar-erp-docs` 공유 패턴 동일. 별도 board 키 불필요(격리 원하면 선택).
- ✅ DNS — `board.heymancar.com` → 54.116.7.83 전파 확인. certbot 바로 가능.

### 주말 실행 런시트 (박스 54.116.7.83, board(ssancar) — car-erp 먼저 살아난 뒤)
> 기준 = board `meetings/deploy-runbook.md` §B. **단 §B-1 "새 인스턴스 프로비저닝"은 생략** — 박스에 nginx/php-fpm/mysql 이미 있음(Django + car-erp ssancar 공유). board 는 vhost+DB+워커만 추가.
1. DB 생성: `board_ssancar` + 전용 user `board_ssancar_user` (이 DB 에만 GRANT, car-erp/Django DB 접근 0).
2. 코드: `/var/www/board-ssancar` 에 `git clone -b master` → composer/npm build.
3. `.env`: scratchpad 템플릿대로. `key:generate` 로 **새 APP_KEY 1회 → 즉시 백업**(payee 암호화).
4. `migrate --force` + `db:seed --class=DatabaseSeeder` (DummyBoardSeeder 절대 금지).
5. nginx vhost `board.heymancar.com`(root=`/var/www/board-ssancar/public`, `client_max_body_size 120M`, php-fpm 소켓 공유) → `nginx -t` → reload.
6. certbot `-d board.heymancar.com` (apex/Django 블록 안 건드림).
7. supervisor `board-ssancar-worker`(queue:work, `startretries=20`/`startsecs=5`) + cron `schedule:run`.
8. `.user.ini`(public, upload 100M/post 110M) — 검차 영상 대비.
9. super 로그인 → `sidebar_brand` 를 ssancar 브랜드로 변경.
10. e2e: won 1대 → car-erp(ssancar) 자동생성 + board `/audit` integration_events 201.

### 후속 (이번 범위 밖)
- **deploy.yml 다중 인스턴스화**: 현재 `master push → Production(heyman) 1곳`. 지금은 박스 수동 `git pull` 배포. 추후 두 번째 environment(`Production-ssancar`)+job 추가로 한 번의 push 가 둘 다 배포하게.
- NICE 게이트웨이 이식(apex `/provide/` location flip) — car-erp 후속.
