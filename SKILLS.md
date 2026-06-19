# SKILLS — board 기술 문서

재구현·이어작업 시 필수 패턴·재발 버그 회피. 도메인/권한/환경은 `CLAUDE.md` 참고.

## 1. Volt 단일파일 컴포넌트
PHP 클래스 + Blade 가 하나의 `.blade.php`. 화면은 `resources/views/livewire/{name}/index.blade.php` → 라우트 `Volt::route('x', 'name.index')`.
```php
<?php
use Livewire\Attributes\{Computed, Layout};
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?int $editingId = null;
    #[Computed] public function listings() { return PurchaseListing::latest()->get(); }
    public function save(): void { /* ... */ }
}; ?>
<div> {{-- 단일 루트 --}} ... </div>
```
- `#[Layout('components.layouts.app')]` 필수(누락 시 500). auth 는 `components.layouts.auth`.
- `#[Computed]` 캐시 무효화 = `unset($this->listings)`. blade 에서 `$this->listings`.
- 폼/액션 후 `session()->flash('ok', ...)` + blade `@if(session('ok'))`.

## 2. 상태머신 + 모델 가드 (`PurchaseListing::booted`)
```php
public const TRANSITIONS = [
    'draft' => ['awaiting_buyer'], 'awaiting_buyer' => ['accepted','rejected'],
    'accepted' => ['won','failed'], 'won' => ['synced'],
    'rejected' => [], 'failed' => [], 'synced' => [],
];
public const IDENTITY_LOCKED = ['vehicle_number', 'vin'];
public bool $allowManagerOverride = false;

static::updating(function (PurchaseListing $l) {
    // (1) 식별값 잠금 — 관리자 override + car-erp 미연동(car_erp_vehicle_id null) 만 정정 허용
    foreach (self::IDENTITY_LOCKED as $col) {
        if ($l->isDirty($col)) {
            $canCorrect = $l->allowManagerOverride && $l->car_erp_vehicle_id === null;
            if (! $canCorrect) throw new \RuntimeException("식별값({$col})...");
        }
    }
    // (2) 전이 검증 (override 우회) + (3) accepted 는 buyer_verdict='accepted' 전제
    if ($l->isDirty('status') && ! $l->allowManagerOverride) {
        $from = $l->getOriginal('status'); $to = $l->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) throw new \RuntimeException("전이 불가 {$from}→{$to}");
        if ($to === 'accepted' && $l->buyer_verdict !== 'accepted') throw new \RuntimeException("바이어 수락 필요");
    }
});
```
- **override 사용처**: manage 화면 `save()` 에서 `$l->allowManagerOverride = true; $l->save();` (시간잠금·전이 무관). 식별값은 그래도 미연동 차량만 허용 — 가드 순서 주의(식별값 체크가 override 체크보다 먼저).
- **accept 전이**: inspection `setVerdict('accepted')` 는 `buyer_verdict`+`status='accepted'` 를 같이 set 후 save → 가드가 새 verdict 값을 보고 통과.

## 3. SalesmanScope (영업 본인격리, Global Scope)
```php
#[ScopedBy([SalesmanScope::class])] class PurchaseListing ...

// SalesmanScope::apply
if ($user && $user->role === 'sales' && ! $user->isSuper()) {
    $builder->where($model->getTable().'.created_by_user_id', $user->id);
}
```
- **영업(sales) 만 본인격리. super·검차·경매·관리는 전체.** 컴포넌트마다 수동 when() 안 써도 구조적으로 IDOR 차단.
- **콘솔/시더(비인증)는 격리 안 됨** → bulk 작업 OK. `withoutGlobalScopes()` 로 명시 해제 가능(테스트/복구용).

## 4. 권한 미들웨어
```php
// EnsureRole — super 바이패스 + 비활성 차단
if (! $user || ! $user->is_active) abort(403);
if ($user->isSuper()) return $next($request);     // super 는 role 무관 통과
if (! in_array($user->role, $roles, true)) abort(403);

// EnsureSuper — /users 전용 (관리 role 도 차단)
if (! $user || ! $user->is_active || ! $user->isSuper()) abort(403);
```
- alias 등록 = `bootstrap/app.php` `$middleware->alias([...])`.
- 라우트: `->middleware('role:sales,manager')` / `->middleware('super')`.

## 5. TimeGate (`App\Support\TimeGate`)
서버시각 단일 판정. 클라 시각 신뢰 금지.
```php
public static function auctionLockAt(?Carbon $day = null): ?Carbon {
    $day = ($day ?? now())->copy();
    if ($day->isWeekend()) return null;               // 주말 미적용
    [$h,$m] = explode(':', config('board.auction_lock_time','10:00'));
    return $day->setTime((int)$h, (int)$m, 0);
}
public static function auctionRegistrationLocked(): bool {
    $lock = self::auctionLockAt(); return $lock !== null && now()->greaterThanOrEqualTo($lock);
}
```
- 경매 등록 시 `lock_at` stamp, `PurchaseListing::isLocked()` = source auction && lock_at && now>=lock_at.
- 테스트는 `Carbon::setTestNow('2026-06-08 11:00:00')` 로 평일/주말 경계 검증(끝에 `setTestNow()` 리셋).

## 6. BoardAudit (감사 단일 경로 = 모델 옵저버)
- **수정 출처 무관 자동기록**: `PurchaseListing::booted()` 의 `static::updated` 옵저버가 변경된 `AUDITED` 필드를 diff 해 `BoardAudit::logChanges($l, $original, $changed, Auth::id())` 호출. 관리/검차/경매/**연동 Job(won→synced)** 어디서 바꾸든 자동. UI 마다 명시 호출하지 말 것(이중기록).
```php
$changed = array_values(array_intersect(self::AUDITED, array_keys($l->getChanges())));
// $original[$f] = $l->getOriginal($f);  → BoardAudit::logChanges(..., Auth::id())
```
- `BoardAudit::logChanges(..., ?int $userId)` — **userId null = 시스템**(비로그인 Job). `board_audit_logs.user_id` nullable.
- **민감필드 마스킹**: `payee_account` 는 로그에 `***`(MASKED 상수). 값 노출 금지(§6e).
- append-only(`const UPDATED_AT = null`). action = status 면 'status_change' 아니면 'field_edit'.
- **표시**: `/audit`(super 전용)에서 status/buyer_verdict/source 코드값을 한글로(`valueLabel()`, 표시시점 변환이라 기존 기록도 한글). 저장값은 코드 그대로(car-erp 대조용).
- **`/manage` 목록**: 전체로드 금지 → `paginate(20)` + `when()` 필터(상태/출처/회신/검색) + KPI 는 별도 `count()`. 필터 컬럼 인덱스(status·source·buyer_verdict·created_by·created_at·car_erp_vehicle_id).

## 7. 사진 업로드 (WithFileUploads + 디스크 분리)
```php
use Livewire\WithFileUploads; ... use WithFileUploads;
public array $photos = [];   // wire:model="photos" multiple, <input capture="environment">

$path = $file->store(config('board.inspection_photo_prefix').'/'.$l->id, config('board.photo_disk'));
$l->photos()->create(['s3_path'=>$path, 'original_name'=>$file->getClientOriginalName(), 'sort'=>...]);
```
- 디스크 = `config('board.photo_disk')` → 로컬 `public`(개발, `php artisan storage:link` 필요), 운영 `s3`(.env `BOARD_PHOTO_DISK=s3`).
- 표시 URL = `photoUrl()` (inspection·auction 양쪽 동일): **디스크 분기** — `public`은 `->url()`(local 드라이버는 temporaryUrl 미지원), `s3`는 `->temporaryUrl()`(비공개 버킷 = presigned 필수). presigned 는 렌더링마다 재서명되면 영상 재생이 리셋되므로 `Cache::remember("photo_url:{path}", 20m, …30m)` 로 문자열 고정(TTL<만료). 사진 렌더는 이 두 컴포넌트뿐(manage 드로어엔 없음).
- **외관 사진만 필터는 연동 A *outbound*(바이어 전송) 전용** — board 내부 화면은 서류·번호판 포함 전부 표시(직원용). §28 레드라인.

## 8. 슬라이드 드로어 패턴
```php
public ?int $editingId = null;
#[Computed] public function editing(): ?PurchaseListing { return $this->editingId ? PurchaseListing::find($this->editingId) : null; }
public function openEdit(int $id): void { $l = ...::findOrFail($id); $this->editingId = $l->id; /* 폼 채움 */ }
public function closeEdit(): void { $this->reset([...]); unset($this->editing); }
```
```blade
@if ($this->editing) @php $e = $this->editing; @endphp
  <div class="fixed inset-0 z-40 bg-black/40" wire:click="closeEdit"></div>
  <div class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white shadow-xl sm:w-[440px]"> ... </div>
@endif
```
- 행 클릭 진입 = `<tr class="cursor-pointer hover:bg-gray-50" wire:click="openEdit({{ $l->id }})">`.
- `findOrFail` 은 SalesmanScope 적용 → 영업은 본인 것만 열림(타인 id 변조 시 404).

## 9. 디자인 시스템 (car-erp SKILLS §10 이식, `resources/css/app.css`)
> ⚠️ **board CSS·UI 는 항상 car-erp 와 맞춘다(권위 = car-erp `resources/css/app.css` + `components/layouts/app/sidebar.blade.php`).** 2026-06-19 대표 지시 — 일관된 룩앤필. 새 컴포넌트/색/사이드바는 **car-erp 것을 미러**(토큰·클래스·구조). 갈라지면 car-erp 기준으로 재정렬. (board 전용 추가 클래스 badge-encar/auction·tbl 등은 유지하되 car-erp 미적에 맞춤.)
- **사이드바**(2026-06-19 car-erp 정렬): 다크(`--color-sidebar-*`·`.app-sidebar`/`.sidebar-item`/`.sidebar-section-label`/`.sidebar-item-collapsed`/`.sidebar-backdrop`/`.sidebar-mobile`) + Alpine 그룹접기(localStorage `navgrp-*`·`sidebar-open`) + 모바일 슬라이드 + 하단 업무가이드(`config('board.work_guide_url')`)/내설정/로그아웃. 레이아웃 = `components/layouts/app/sidebar.blade.php`.
- `@theme` 에 `--color-primary:#7c6fcd`(보라) 등. **라이트 모드**(스타터킷 `class="dark"` 제거함).
- 유틸: `.card`/`.card-sm` · `.btn-primary`/`.btn-outline`/`.btn-ghost`/`.btn-green`/`.btn-sm` · `.tab-pill` · `.pill-count` · `.input-base`/`.label-base` · `.tbl`(th/td) · `.kpi`.
- 뱃지: `.badge` + `.badge-{blue,teal,purple,amber,red,green,gray,encar,auction}`.
- 도메인 매핑: 상태 draft=blue / awaiting=amber / accepted=teal(엔카)·purple(경매) / won=green / rejected=red / failed·synced=gray. 출처 encar=blue / auction=amber.
- 새 클래스 추가 후 **`npm run build`** 필요(Vite). 블레이드만 바꾸면 `view:clear`.

## 10. 테스트 (PHPUnit — Pest 아님)
- `tests/Feature/BoardTest.php`, 클래스 스타일 `extends Tests\TestCase` + `use RefreshDatabase`. `phpunit.xml` = sqlite `:memory:`.
- 컴포넌트 = `Livewire\Volt\Volt::test('listings.index')->set(...)->call('save')->assertHasNoErrors()`.
- 페이지 = `$this->actingAs($u)->get('/manage')->assertOk()` (레이아웃+컴포넌트 풀 렌더 검증).
- 헬퍼: `mkUser($role, $email=null, $permission='user')`, `mkListing($owner, $attr=[])`, `assertItThrows($fn)`(베이스 `assertThrows` 와 충돌해 이름 다름).
- 예외 다건 검증은 try/catch(`assertItThrows`) — `expectException` 은 첫 throw 에서 멈춤.

## 11. 자주 발생한 버그
1. **cwd 사고** — car-erp 디렉터리에서 board artisan/migrate 실행 → car-erp DB 오염. 항상 `cd /c/xampp/htdocs/board` 명시 + `\DB::connection()->getDatabaseName()` 확인. (CLAUDE.md 경고)
2. **pint 를 .blade.php 에 돌리면 Volt 클래스 대량 reformat·깨짐** — `vendor/bin/pint app database tests bootstrap` (resources 제외). `.php` 만.
3. **`self::CONST` 를 Volt blade 에서 접근 불가** — 익명 클래스라 안 됨. `$this->method()` 또는 `\App\Models\X::CONST` FQN. (manage 의 fieldLabel 처럼 public 메서드로)
4. **MariaDB 시스템테이블 손상** — `CREATE USER`/`GRANT` 시 `Index for table 'db' is corrupt` → `REPAIR TABLE mysql.db; REPAIR TABLE mysql.tables_priv;` 후 재시도.
5. **role 값 vs permission 혼동** — `manager`(관리 role) ≠ super(시스템관리자 permission). `/users` 는 super 전용, `/manage` 는 관리·super. `isManager()`=role, `isSuper()`=permission.
6. **식별값 가드 순서** — IDENTITY_LOCKED 체크가 override 체크보다 먼저라 관리자도 연동된(car_erp_vehicle_id≠null) 차량 VIN 은 못 바꿈. 미연동만 정정.
7. **시더 재실행** — listings `updateOrCreate(by vin)` 가 status 를 시드값으로 되돌리는데, DB 현재 status 가 다르면 전이 가드에 걸릴 수 있음. UI 로 상태 진행시킨 뒤 `db:seed` 재실행 주의(필요시 `migrate:fresh --seed` 또는 query update 로 복구).
8. **enum unique + NULL** — `unique('vin')` / `unique(['auction_venue','lot_number'])` 는 MySQL/MariaDB 에서 NULL 다중 허용 → 엔카(venue/lot NULL)·VIN 없는 행 충돌 안 함.
9. **Tailwind v4 `!important` 위치 (★2회 발생)** — v4 는 **후행** `bg-red-500!`, 선행 `!bg-red-500`(v3 문법)은 **무시됨**. `input-base{width:100%}`·`btn-outline{background:#fff}` 같은 커스텀 클래스를 못 덮어 "세로로 쌓임/배경 안 변함" 증상. 해결 = 인라인 `style`/`@style` 디렉티브(확실, 빌드 불필요) 또는 grid 레이아웃. blade 새 클래스는 `npm run build` 필요도 주의.
10. **목록 전체로드 금지** — `->latest()->get()` 는 수천 건에서 느림. `/manage` 처럼 `paginate()` + DB 필터 + 별도 `count()` KPI. (옛 코드 답습 말 것)

## 12. 연동 B 계약 — board "보내는 절반" (수신 = car-erp/heyman)
> 두 앱(board·car-erp)이 만나는 **유일한 접점 = 이 API 계약**. DB·보안경계 다른 별도 앱이라 합치지 않고 계약으로 느슨하게 연결.
> **계약은 두 면**: board=보내는 스펙(여기), car-erp=받는 스펙(car-erp docs). **문서 복사 금지(drift) → 각자 자기 절반 + 상호 링크.** 수신 로직(VIN 멱등·영업 매칭·vehicle 생성)은 car-erp 책임.

**전송**: `POST {CAR_ERP_BASE_URL}/api/internal/purchase-sync` (HMAC 서명 + HTTPS). `status='won'` 만, `dispatch()->afterCommit()`, 큐 비동기. **구현 = `App\Jobs\SyncWonListingToCarErp`** (dispatch 훅 = `PurchaseListing::updated` 에서 status→won 단일 지점 → auction conclude·manage override 공통).

**HMAC 서명 (계약 — car-erp 가 동일하게 검증)**:
- 헤더 `X-Board-Signature: sha256=<hex>`.
- 서명대상 = **직렬화된 raw request body** (`json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`). car-erp 는 **수신 raw body 그대로** `hash_hmac('sha256', $rawBody, $secret)` 로 재계산해 `hash_equals` 비교 — **재직렬화 금지**(바이트 달라지면 불일치). 비밀키 = `CAR_ERP_HMAC_SECRET` (양쪽 공유).
- board 전송은 `Http::withBody($body,'application/json')` 로 그 raw body 를 그대로 전송(프레임워크 재인코딩 회피).

**⚠️ 매칭키 = `vehicle_number` (VIN 아님 — 2026-06-15 정정)**: board 는 **VIN 을 모른다**. VIN 은 **NICE 차량조회로만** 나오고 그건 **car-erp 책임**이다. board 가 가진 건 **차량번호 + 소유자명**뿐. 그래서 board 는 `vehicle_number + owner_name` 을 보내고 **car-erp 가 NICE 로 VIN 을 조회**해 채운다. **멱등/매칭/식별 키 = `vehicle_number`** (board IDENTITY_LOCKED 도 vehicle_number 가 실질 키 — vin 은 항상 null). → 과거 이 계약을 vin 기반으로 짰던 건 drift(이 결정이 문서에 없어서). 다시 vin 으로 되돌리지 말 것.

**payload**:
```json
{
  "contract_version": 1,
  "vehicle_number": "...", "owner_name": "...", "source": "encar|auction",
  "final_price": 0, "salesman_email": "...", "car_erp_salesman_id": null,
  "c_no": null, "payee_name": null, "payee_bank": null, "payee_account": null
}
```
- `owner_name`(소유자/차주명) = car-erp NICE 조회 입력값. board 입력 UX = payee 와 동일(매입예정 영업 선택입력 → 경매/구매 드로어 보정). nullable 이지만 없으면 car-erp NICE 불가 → car-erp 는 owner_name 없으면 vehicle_number 로만 생성 후 VIN 수동.
- **vin 은 payload 에 없음**(board 가 모름). car-erp 가 NICE 로 채워 `nice_reg_vin` 에 저장.
- `salesman_email` = board 영업의 **`users.car_erp_salesman_email`(오버라이드) ?: 로그인 email**. car-erp 가 이 이메일로 salesmen 매칭. (`/users` 에서 숫자 id 대신 car-erp 이메일만 입력 — id 는 DB 봐야 알아서 폐기. `car_erp_salesman_id` 는 잔존하나 보통 null.)
- **응답(계약)**: `2xx` + `{"vehicle_id": <int>}`. board 는 이 id 를 `car_erp_vehicle_id` 에 저장 후 `won→synced` 전이. 비-2xx 또는 vehicle_id 없으면 Job 예외 → 큐 재시도(`$tries=5`, backoff 60/300/900/1800s).
- **버전·전방호환**: `contract_version` 명시. **양쪽 모두 "모르는 필드는 무시"** → 필드 추가해도 안 깨짐.
- **로그**: 모든 시도(성공/실패) = `integration_events`(outbound/car_erp/purchase_sync) append-only. **`payee_account` 는 로그에 `***` 마스킹**(전송 본문엔 실값). board_audit_logs 와 별개.
- **안전밸브**: `services.car_erp.base_url`/`hmac_secret` 미설정 시 Job no-op → car-erp 수신측 배포 전 board 를 master 배포해도 안 터짐(아무것도 안 보냄).
- **보안경계**: RRN/전화/서류 미포함. `payee_account` 는 board 암호화 보관(§6e), 전송은 HMAC+HTTPS 한정 → car-erp 매입탭 정산계좌로 수신.
- **멱등**: board `car_erp_vehicle_id` null 가드 + car-erp `vehicle_number` 사전조회(중복=스킵, NICE 재조회 방지). 응답 `{vehicle_id}` → board `purchase_listings.car_erp_vehicle_id` 채움.
- ⚠️ **계약 변경 시 배포 순서**: 필드 추가/변경은 **수신측(car-erp) 먼저 배포**(받을 준비) → 그 다음 board 가 보내기 시작. (car-erp 배포 `artisan down` 1~3분은 board 큐+재시도가 자동 흡수.)
- **수신 스펙(권위) = car-erp docs**: `PurchaseSyncController` — 영업 매칭(이메일→salesman→`manager_user_id`로 담당 관리 자동 솔팅), `vehicle_number` 멱등, **NICE(vehicle_number+owner_name) → VIN**, payee→정산계좌.
- 선행: car-erp API 1개(**승인됨 2026-06-15**) + HMAC + 큐 워커(board **설치 완료**). board 는 `status='won'` 가드 + afterCommit.
- **🟢 진행상태 (2026-06-15, 운영 LIVE 완료)**: board+car-erp 양쪽 master 배포 + 로컬 e2e 통과. car-erp 수신측 = `daa4c16`(1차) → `44eab1d`(2차: sales_channel='heyman' 제거(enum export 단일), vin→vehicle_number 멱등 + NICE(vehicle_number+owner_name)→VIN). 양쪽 운영 `.env` `CAR_ERP_HMAC_SECRET` 공유(값=문서 미기재) + board `CAR_ERP_BASE_URL=https://heysellcar.com`. 안전밸브·멱등 작동. **첫 실거래 won 대기** — 검증: `/audit` integration_events 201 + car-erp 매입목록. 핸드오프 = `meetings/handoff-car-erp-purchase-sync.md`.

### 연동 B/A 추가 스키마 (codex/gemini 리뷰 수용, 2026-06-12)
- **`integration_events`** (append-only, board_audit_logs 와 별개): `id · direction(outbound/inbound) · target(car_erp/respond_io) · event_type · purchase_listing_id(nullable) · external_event_id(nullable, inbound 중복제거 키) · request_payload(json) · response_status · response_body · error · created_at`. updated_at 없음. **연동 B 에서 신설, 연동 A inbound 가 `external_event_id` 로 멱등성 확보 시 재사용.**
- **`purchase_listings` 추가 컬럼**(연동 A 시): `c_no`(string nullable, **index, non-unique** — 조인 thread 이지 중복키 아님. dedup 은 그대로 vin·(venue,lot)) · `respond_contact_id`(string nullable) · `respond_conversation_id`(string nullable). **`phone_hash` 등 전화 파생값 금지**(contact_id 로 충분 + PII 최소보유).
  - **c_no 채움 = 출처 분기**: ssancar.com 클릭 유입만 c_no 따라옴(우리 소유 → 채팅 링크에 c_no 보장) → 연동 A inbound 가 자동 채움. **엔카·기타 출처는 c_no = NULL 정상**(예외만 수동입력). 모든 listing 필수값 아님.
- **`config/services.php`**: `car_erp` => `{base_url, hmac_secret}`, `respond_io` => `{api_token, webhook_secret}`. (`.env` 키: `CAR_ERP_BASE_URL`/`CAR_ERP_HMAC_SECRET`/`RESPOND_API_TOKEN`/`RESPOND_WEBHOOK_SECRET`)
- **멱등 컬럼 비대화 금지**: B 의 outbound 멱등은 `car_erp_vehicle_id` null 가드 + car-erp VIN 사전조회로 끝. `idempotency_key`/`sync_attempts`/`last_sync_error` 를 purchase_listings 에 추가하지 말 것 — 시도/에러 이력은 `integration_events` 로.
- **api 라우팅**: `bootstrap/app.php` 에 `api: __DIR__.'/../routes/api.php'` 추가 + `WebhookController`(HMAC 검증) — 현재 web only.

## 13. 지급 게이트웨이 — 계약금 자동이체 (board → car-erp → 하나은행 펌뱅킹)
> **권위 스펙 = car-erp `docs/integration/payment-disbursement-gateway.md`** (경로로 읽을 것, **복사 금지 — drift 방지**. 연동 B 상호링크 규칙 동일). **상태 = 설계/미구현, 인지 기록만** (2026-06-18). 구현 착수 전 Jin 확정 필요.
- **구조**: board(영업팀장 **건당 승인**) → HMAC 서명 요청 → car-erp **단일 지급 게이트웨이**(`DisbursementService`: 멱등·한도·계좌 화이트리스트·예금주조회·감사) → 하나은행 펌뱅킹 계약금 **원화 국내이체**. **자금 자격증명(VAN·은행키)은 car-erp 한 곳에만** — board 는 요청만 보냄(뚫려도 돈 안 샘).
- **board 절반(추후 작업)**: 입력란 신설 `계약금(deposit_amount)`·`이체완료일`·`거래관리번호`(멱등키) + **영업팀장 승인 시 car-erp 게이트웨이로 HMAC 서명 요청**. 수신 스펙(권위)에 맞춰 보냄(필드 contract = 권위 파일 §4).
- **미확정(권위 파일 §5)**: 배송금액 매핑(#2)·매입가 통화(#3)·VAN사(#6)·한도(#8). → 확정 전 구현 금지. 연동 B(`§12`)와 **별개 신설/확장**.

## 14. 영업 포털 — car-erp 읽기 미러(재무·선적요청·서류)
> **권위 계약 = car-erp `docs/integration/board-portal-api.md`** (경로로 읽을 것, **복사 금지 — drift**. 인계 출처 = `meetings/handoff-car-erp-board-portal.md`). 영업은 board만 씀 → car-erp 원장을 board 에서 읽기.
- **`CarErpReadService`**(HMAC **GET**): canonical = `METHOD\nPATH?SORTED_QUERY\nX-Timestamp\nBODY`(계약 §1, 바이트 일치 — `canonical()` 격리 + 핀 테스트). 시크릿 = **`CAR_ERP_READ_HMAC_SECRET`**(쓰기 `CAR_ERP_HMAC_SECRET`와 분리). 헤더 X-Board-Signature/X-Timestamp/X-Nonce. **미설정 시 no-op 안전밸브**.
- **degrade 3상태**: 미설정/401/5xx/403 → "조회 불가"(**절대 0원/완납 coerce 금지**) · 값 null(미수금 KRW=환율 미입력) **보존** · 값 표시. salesman_email = **Auth 본인(`car_erp_salesman_email ?: email`)만**(요청 파라미터 금지).
- **서류 = 선적 4종만**(`roro_*`·`container_*` invoice_packing/contract) board 측 화이트리스트 강제. 마진 raw·RRN·계좌 미수신/미표시. POST 선적요청 시 salesman_email **쿼리+바디**(스코프 미들웨어=쿼리).
- 화면 `/portal`(role sales,manager) 탭: ④재무(요약/미수/매입/판매/정산) + **③선적요청**(shippable 바이어별 묶음→컨사이니+RORO/CONTAINER→POST, 응답 created/skipped) + **①②서류**(선택차 method별 2종 xlsx 스트림 다운로드). 전부 구현.
- **응답 키 정합(car-erp 컨트롤러 확인 2026-06-18)**: 리스트 = `{count, data:[...]}`(items=`data` 키). finance=`unpaid_total_krw·purchase_unpaid_total·fx_missing_count·settlement_pending_count`. receivables/sales 바이어=`buyer`. shippable=`{count,data:[{vehicle_id,vehicle_number,buyer:{id,name},consignees:[{id,name}]}]}`. shipping-request 응답=`{created,skipped}`. 알람=car-erp `TaskAlarm shipping_requested`(target `수출통관`).
- ⚠️ **canonical 정합(라이브 검증, 2026-06-19)**: car-erp `VerifyBoardReadHmac` 은 ksort 후 **`http_build_query`(urlencode)** — board 도 동일(스펙 §1 텍스트 "k=v&"는 모호, 구현이 권위). 8개 엔드포인트 board 8002→car-erp 8001 실호출 200 통과(매칭 salesman moo@car-erp.test).
- **by-buyer 집계**(`GET /by-buyer`, car-erp dev b26a3f8): `{buyer_id, buyer, vehicle_count, sales_by_currency{통화:합}, payout_total_krw, payout_paid_krw}`. 판매내역=통화별 판매합, 정산내역=바이어별 payout 으로 이 엔드포인트 사용(per-vehicle 아님). 매입은 buyer 무관(평면).
- **UI**: 요약 금액 **한글 축약**(`abbrevKrw`, 7억 436만원, title=원금액) + **월별 실적**(판매 건수·정산 실지급·매입, sale_date/confirmed_at/purchase_date 집계, 판매는 통화혼재라 건수만). 미수금=컬럼정렬+완납(0원)숨김. 선적요청/미수금 바이어별 collapse.
