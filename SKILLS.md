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
- 유틸: `.card`/`.card-sm`/`.card-tight` · `.btn-primary`/`.btn-outline`/`.btn-ghost`/`.btn-green`/`.btn-sm` · `.tab-pill` · `.pill-count` · `.input-base`/`.label-base` · `.tbl`(th/td) · `.kpi`.
- **모바일 반응형 표 = 이중 렌더 (2026-06-19, car-erp 미러)**: `.tbl` 은 폰에서 옆으로 밀어야 함 → 데스크톱=`hidden sm:block` 표, 모바일=`sm:hidden space-y-2` 세로 카드(`.card-tight`)로 **둘 다 작성**. 행 액션(`wire:click`·수락/거절·수정 버튼)은 카드에도 복제. 적용=listings·verdicts·auction·manage·inspection·portal(표5종 — `x-show` 접기 안의 표는 **카드도 같은 `x-show` 안**에 둬야 같이 접힘). **users·audit(super 전용·JSON payload)는 `overflow-x-auto` 유지**(카드화가 오히려 나쁨 — car-erp 도 동일). 카드 안 긴 토큰(VIN/email/contact_id)은 `Str::limit`/`truncate` 필수. car-erp 권위 예시 = `livewire/erp/vehicles/index.blade.php`.
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
9. **Livewire morph + Alpine 접기 카드 = `wire:key` 필수 (2026-06-19)** — `@forelse` 안에서 `x-data="{ open }"` 접기 카드를 `wire:key` 없이 쓰면, 탭 전환 등 Livewire 재렌더(morph)가 이전 DOM 노드를 재사용해 **첫 항목의 Alpine 상태가 깨짐**(토글 불능·계속 펼침, 나머지는 정상). 증상: 기본 탭은 멀쩡한데 `setTab` 으로 들어가는 탭의 첫 카드만 안 접힘. 해결 = 반복 루트 요소마다 `wire:key="...-{{ $loop->index }}"`. (portal 판매/미수금/선적/월별에서 발생·수정.)
9. **Tailwind v4 `!important` 위치 (★2회 발생)** — v4 는 **후행** `bg-red-500!`, 선행 `!bg-red-500`(v3 문법)은 **무시됨**. `input-base{width:100%}`·`btn-outline{background:#fff}` 같은 커스텀 클래스를 못 덮어 "세로로 쌓임/배경 안 변함" 증상. 해결 = 인라인 `style`/`@style` 디렉티브(확실, 빌드 불필요) 또는 grid 레이아웃. blade 새 클래스는 `npm run build` 필요도 주의.
10. **목록 전체로드 금지** — `->latest()->get()` 는 수천 건에서 느림. `/manage` 처럼 `paginate()` + DB 필터 + 별도 `count()` KPI. (옛 코드 답습 말 것)
11. **Livewire temp-upload `mimes` allowlist 가 새 파일타입을 조용히 거부 (2026-06-22)** — `config/livewire.php` `temporary_file_upload.rules` 의 `mimes:...` 는 **전역**. 여기 없는 확장자(예: pdf·xlsx)는 `wire:model` 업로드 단계에서 **컴포넌트 에러 없이 드롭**됨 → save 시 그 파일만 안 들어옴(딴 파일은 정상, 디버깅 어려움). + `<input accept="...">` 도 picker 를 제한하니 같이 확인. → board 는 **mimes allowlist 제거**(rules=`file|max`)하고 **블록리스트**(Jin: "실행파일만 차단")로 운영 = `App\Support\UploadGuard::isExecutable()`(config `board.blocked_upload_ext`)를 **저장 직전** 양 업로드 경로(listings·inspection)에서 호출. 영업 첨부 = listings **첨부파일 1칸**(`salesFiles`), 저장 시 mime 으로 자동분류(`image/*`→sales_photo / 그 외→sales_document) → `inspection_photos.kind`. 연동 B 는 sales_* 만 전송(§12). 미리보기 = TemporaryUploadedFile `isPreviewable()`+`temporaryUrl()`(이미지만), 그 외 파일명 칩.

12. **`x-data="{...}"` 안 주석에 큰따옴표 → Alpine 이 통째로 죽음 (★2026-07-31 실제 발생)** — `x-data` 는 **큰따옴표 HTML 속성**이라 값 안의 `"` 하나에서 속성이 끊긴다. 주석이라도 예외 없음. 사이드바 x-data 주석에 `"열렸다가 즉시 접힘"` 을 넣었다가 `toggle()`·`closeMobile()`·`isMobile` 이 전부 사라져 **사이드바가 아예 안 열렸다**(에러 없이 조용히 죽음 — 콘솔도 안 봄). 여러 줄 JS 를 속성에 넣는 곳(레이아웃 사이드바 등)은 **작은따옴표만** 쓸 것. 회귀 테스트 = `test_sidebar_alpine_data_attribute_is_not_truncated`(x-data 가 `toggle()`·`closeMobile()` 까지 온전히 렌더되는지 — 잘리면 실패).
13. **서류 타입에 method 접두를 잘못 붙이면 car-erp 403 (2026-08-01)** — 선적 4종만 `roro_`/`container_` 접두다. **`sales_contract`·`invoice`(프로포마 인보이스)는 리터럴 타입** — 접두를 붙이면 화이트리스트 밖 이름이 되어 403. ⚠️ 프로포마 인보이스의 car-erp 타입명은 **`invoice`**(`proforma_invoice` 아님)이고 선적 `roro_invoice_packing` 과 **다른 서류**다. 그리고 board 화이트리스트(`ALLOWED_DOC_TYPES`)에만 넣어도 소용없다 — **car-erp `BOARD_ALLOWED_TYPES` 에도 있어야 200**(실제로 `sales_contract` 가 board 에만 있어 몇 주간 전부 403이었다).

14. **`Http::assertSent` 는 "한 건이라도 만족"이라 조용히 위양성 (★2026-08-08 실제로 냈다)** — 통과 조건에 `! str_contains($req->url(), '/requests')` 를 넣었더니, 컴포넌트 mount 가 쏘는 **다른 호출**(`/by-buyer`·`/sales`)이 그 조건을 먼저 만족시켜 **정작 검증하려던 본문을 안 보고 통과**했다. 틀린 `vehicle_ids` 를 보내도 초록불이다. → **관심 없는 요청은 `return false` 로 떨궈서** 대상 요청의 본문으로만 판정하게 할 것.
    ```php
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), '/requests')) { return false; }   // 통과시키면 안 된다
        $b = json_decode($req->body(), true);
        return ($b['vehicle_ids'] ?? null) === [56] && ($b['buyer_id'] ?? null) === 9;
    });
    ```
15. **nullable 컬럼에 `where(col,'!=',x)` 를 쓰면 NULL 행이 같이 사라진다 (2026-08-07)** — SQL 3값 논리라 `NULL != 'x'` 는 참이 아니다. `purchase_listings.origin` 은 nullable(구행 백필 이후 유입분)이라 `where('origin','!=','self_inspection')` 만 쓰면 **현지확인 화면에서 구행이 통째로 증발**한다. → 전용 스코프로 감쌀 것: `whereNull(col)->orWhere(col,'!=',x)` (`PurchaseListing::scopeWhereNotSelfInspection`).
16. **car-erp `inStock()` 은 출고일뿐 아니라 "매입 완납"까지 본다 (★2026-08-09, 설계가 통째로 틀릴 뻔)** — `whereRaw(purchaseUnpaidRawExpr().' <= 0')` 이 들어 있어 **미지급이 남은 차는 재고가 아니다**. 그런데 §11 [입금요청]을 보낼 차가 정확히 그 차들이다. 재고 3분류(일반/선적전/출고완료)만 미러하면 **버튼 달 곳이 사라진다**. → ERP 재고관리의 **「지급대기」(`awaiting_payment`) 포함 4분류**를 쓰고, 그게 board 기본 탭이다. ⚠️ **car-erp 스코프를 미러할 땐 이름만 보지 말고 정의(scope 본문)를 열어볼 것.**
17. **`assertSee`로 칩을 검증했는데 같은 문자열의 필터 pill 이 대신 통과시킨다 (★2026-08-09, §11-14 의 화면판)** — 운항 칩(ERP 라벨 `운항중`)과 필터 pill 라벨(`🚢 운항중`)이 **같은 문자열**이라, `assertSee('운항중')` 은 **칩을 아예 안 그려도 초록불**이다(뮤테이션으로 실측 확인). → 화면 검증은 **그 요소만 낼 수 있는 값**으로 할 것(선박명·`ETA 2026-07-20`), 그리고 ERP 라벨 통과 여부는 **board 에 없는 라벨**(`sailing_status: 'ERP가정한라벨'`)을 흘려서 본다. ⚠️ **새 UI 테스트는 해당 코드를 죽여보고 실제로 빨간불이 되는지 한 번 확인**(`@if (false)` 로 충분).
18. **같은 스펙 절이라도 "필드 오는 엔드포인트" ≠ "필터 받는 엔드포인트" (2026-08-09)** — car-erp §12 는 `sailing` **필드**를 `/sales`·`/inventory` 양쪽에 싣지만 **필터는 `/sales` 만** 읽는다(`InternalPortalController::inventory` 에 `sailing` when 절 없음 — 실측). 표를 보고 양쪽에 파라미터를 얹으면 서버가 **조용히 무시**해 "운항중만 보기인데 전부 보이는" 화면이 된다(422 도 안 난다). → 필터를 붙이기 전에 **수신측 컨트롤러에서 그 쿼리를 실제로 읽는지** 확인할 것.
19. **상대가 아직 배포 안 한 필드에 UI 를 열어두면 그 UI 가 거짓말을 한다 (2026-08-09)** — board 가 ERP 보다 먼저 나가면(또는 한쪽 박스만 배포되면) 필터는 무시되고 화면은 필터된 척한다. → **응답 행에 그 키가 있는지로 지원 여부를 판정**하고(값 `null` 은 정상 데이터라 키 존재로 봐야 한다) 없으면 **필터 UI 자체를 숨긴다**. ⚠️ 단 **필터가 걸린 동안은 무조건 노출** — 0건이 나왔을 때 pill 이 사라지면 되돌릴 방법이 없다.
20. **셀프검차매입이 금액을 넣을 화면이 없어 won 이 조용히 422 로 죽었다 (★2026-08-10 heymanboard 실장애, 67도4322)** — 차값(`car_cost`)은 **`/listings` 등록폼에 입력칸이 아예 없다**(링크 자동채움 전용). 정상 차는 그 뒤 `/inspection` 최종금액이나 `/forwarding` 견적에서 채워지는데 **셀프검차는 두 화면을 다 건너뛴다**. `/auction` 은 금액을 표시만 했고 구매확정도 금액을 안 봤다 → won 진입 → 연동 B → car-erp `final_price: required_without:purchase_price_krw` → **422**, 그런데 영업 화면엔 "처리 완료"만 떴다. → ① `/auction` 드로어에 **차값 입력칸**(accepted·won), ② `conclude('won')` 이 금액 없으면 **거부**, ③ won+미연동 차는 금액 저장 시 **자동 재발사**(`/manage` 재전송은 super 전용이라 영업에겐 손이 없다). ⚠️ **새 origin/씬을 만들 땐 "그 경로가 건너뛰는 화면에서 채워지던 필수값"을 세어볼 것** — 상태 전이만 맞추면 되는 게 아니다.
21. **`integration_events` 가 실패 사유를 안 남긴다 (2026-08-10)** — Job 이 `'error' => 'HTTP '.$response->status()` 만 저장해서 `/audit` 에도 `HTTP 422` 뿐, **어느 필드가 걸렸는지 없다**. 위 장애도 payload 를 운영에서 다시 만들어 봐야 원인이 나왔다. 응답 본문 일부를 같이 남기는 게 다음 후보(⚠️ `payee_account` 등 민감값이 에코될 수 있으니 마스킹 후).
22. **`Http::fake()` 는 덮어쓰기가 아니라 머지다 (2026-08-10)** — 한 테스트에서 두 번 부르면 **먼저 등록한 `'*'` 와일드카드가 뒤에 등록한 구체 패턴을 가린다**. 케이스별로 다른 응답이 필요하면 **테스트를 나눌 것**(같은 메서드 안에서 재설정하면 조용히 첫 스텁이 계속 응답한다 — 실제로 락 테스트가 이걸로 위양성처럼 죽었다).
23. **PHPDoc 안에 `*/` 가 들어가면 주석이 거기서 끝난다 (2026-08-10)** — URL 패턴(`*` + `/buyers*`)을 주석에 그대로 적었다가 **파일 전체 파스 에러**가 났다. 테스트가 아니라 `php -l` 로만 잡힌다. 주석엔 경로만 쓰고 글롭 별표는 빼거나 백틱 밖으로 옮길 것.

## 12. 연동 B 계약 — board "보내는 절반" (수신 = car-erp/heyman)
> 두 앱(board·car-erp)이 만나는 **유일한 접점 = 이 API 계약**. DB·보안경계 다른 별도 앱이라 합치지 않고 계약으로 느슨하게 연결.
> **계약은 두 면**: board=보내는 스펙(여기), car-erp=받는 스펙(car-erp docs). **문서 복사 금지(drift) → 각자 자기 절반 + 상호 링크.** 수신 로직(VIN 멱등·영업 매칭·vehicle 생성)은 car-erp 책임.

**전송**: `POST {CAR_ERP_BASE_URL}/api/internal/purchase-sync` (HMAC 서명 + HTTPS). `status='won'` 만, `dispatch()->afterCommit()`, 큐 비동기. **구현 = `App\Jobs\SyncWonListingToCarErp`** (dispatch 훅 = `PurchaseListing::updated` 에서 status→won 단일 지점 → auction conclude·manage override 공통).

**HMAC 서명 (계약 — car-erp 가 동일하게 검증)**:
- 헤더 `X-Board-Signature: sha256=<hex>`.
- 서명대상 = **직렬화된 raw request body** (`json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`). car-erp 는 **수신 raw body 그대로** `hash_hmac('sha256', $rawBody, $secret)` 로 재계산해 `hash_equals` 비교 — **재직렬화 금지**(바이트 달라지면 불일치). 비밀키 = `CAR_ERP_HMAC_SECRET` (양쪽 공유).
- board 전송은 `Http::withBody($body,'application/json')` 로 그 raw body 를 그대로 전송(프레임워크 재인코딩 회피).

**⚠️ 매칭키 = `vehicle_number` (VIN 아님 — 2026-06-15 정정)**: board 는 **VIN 을 모른다**. VIN 은 **NICE 차량조회로만** 나오고 그건 **car-erp 책임**이다. board 가 가진 건 **차량번호 + 소유자명**뿐. 그래서 board 는 `vehicle_number + owner_name` 을 보내고 **car-erp 가 NICE 로 VIN 을 조회**해 채운다. **멱등/매칭/식별 키 = `vehicle_number`** (board IDENTITY_LOCKED 도 vehicle_number 가 실질 키 — vin 은 항상 null). → 과거 이 계약을 vin 기반으로 짰던 건 drift(이 결정이 문서에 없어서). 다시 vin 으로 되돌리지 말 것.

**payload** (`contract_version: 4` — v4 = v3 + 매도비 계좌, 전방호환 v1~v3 수용):
```json
{
  "contract_version": 4,
  "vehicle_number": "...", "owner_name": "...", "source": "encar|auction",
  "final_price": 0, "salesman_email": "...", "car_erp_salesman_id": null,
  "c_no": null, "payee_name": null, "payee_bank": null, "payee_account": null,
  "selling_fee_payee_name": null, "selling_fee_payee_bank": null, "selling_fee_payee_account": null,
  "attachments": [{ "s3_path": "...", "original_name": "...", "kind": "sales_photo|sales_document", "sort": 1 }],
  "purchase_price_krw": 0, "selling_fee_krw": 0,
  "transport_fee": 0, "sale_price": 0, "sale_currency": "USD|EUR|KRW", "sale_exchange_rate": 0,
  "buyer_id": null, "consignee_id": null
}
```
- `owner_name`(소유자/차주명) = car-erp NICE 조회 입력값. board 입력 UX = payee 와 동일(매입예정 영업 선택입력 → 경매/구매 드로어 보정). nullable 이지만 없으면 car-erp NICE 불가 → car-erp 는 owner_name 없으면 vehicle_number 로만 생성 후 VIN 수동.
- **vin 은 payload 에 없음**(board 가 모름). car-erp 가 NICE 로 채워 `nice_reg_vin` 에 저장.
- `salesman_email` = board 영업의 **`users.car_erp_salesman_email`(오버라이드) ?: 로그인 email**. car-erp 가 이 이메일로 salesmen 매칭. (`/users` 에서 숫자 id 대신 car-erp 이메일만 입력 — id 는 DB 봐야 알아서 폐기. `car_erp_salesman_id` 는 잔존하나 보통 null.)
- **응답(계약)**: `2xx` + `{"vehicle_id": <int>}`. board 는 이 id 를 `car_erp_vehicle_id` 에 저장 후 `won→synced` 전이. 비-2xx 또는 vehicle_id 없으면 Job 예외 → 큐 재시도(`$tries=5`, backoff 60/300/900/1800s).
- **`attachments[]` (v2, 차량 첨부 — 영업이 board 에 올린 사진+서류)**: `s3_path`(공유 버킷 `heysellcar-erp-docs` 키, **바이트 아님**) · `original_name` · `kind`(sales_photo 외관 / sales_document 차량등록증 등) · `sort`. **검차 사진(kind=inspection)은 제외** — 그건 바이어 전송(§28) 전용. 빈 배열 가능. **1회 발사**(won→synced, `car_erp_vehicle_id` null 가드). synced 후 추가/누락 보완은 **car-erp [관리] 몫**(board 재전송 경로 없음 — 영업은 won 전 자료확보가 일반적). 수신측(car-erp): 차량 첨부탭(최대 10건 cap·`s3_path` 중복스킵)에 행 생성, S3 접근방식(키 직접참조 vs 자기 prefix 복사)은 car-erp 결정. 권위 인계 = `meetings/handoff-car-erp-vehicle-attachments.md`. **car-erp 무수정 예외 확장 → car-erp 먼저 배포.**
- **`v3` 금액/바이어 (2026-06-23, 권위 인계 = `meetings/handoff-car-erp-amount-mapping.md`)**: 매입=KRW 원장 / 판매=확정통화. `purchase_price_krw`(구입금액=차값−할인, **매도비·배송 제외** → car-erp purchase_price 교정) · `selling_fee_krw`(매도비) · `transport_fee`(운임비 **판매통화 환산** = shipping_usd×USD환율/판매환율 — ⚠️car-erp가 sale_price와 직접합산하므로 USD 아닌 **판매통화**) · `sale_price`(차량금액→판매통화) · `sale_currency`(현지확인 확정 offer_currency) · `sale_exchange_rate`(확정 시점 환율, 관리가 ERP서 미세조정) · `buyer_id`/`consignee_id`(경매/구매 드롭다운 선택, car-erp `/buyers`·`/consignees` 본인스코프, 미선택 null). car-erp v3 수신기 구현됨(SUPPORTED_VERSIONS=[1,2,3]) — ⚠️ **운임비 통화 버그**(transport_fee_usd raw 저장) 수정 + **car-erp 먼저 배포** 후 board v3 전환(안 그럼 422). 근거 역산 = `meetings/board-carerp-amount-mapping.md`(차=원가판매·수익=부가세9%).
- **`v4` 매도비 계좌 (2026-07-03, 권위 인계 = `meetings/handoff-car-erp-purchase-two-accounts.md`)**: 매입 정산계좌를 **2개로 분리** — 기존 `payee_*`(매입가/판매자 계좌 → car-erp `purchase_seller_*`) + 신규 **`selling_fee_payee_name`/`selling_fee_payee_bank`/`selling_fee_payee_account`**(매도비 계좌 = 판매자와 **다른 대상**, 영업 직접입력, nullable, 계좌 `encrypted`). 금액(purchase_price_krw/selling_fee_krw)은 v3서 이미 분리 — 이번은 **계좌만** 2개로. car-erp 제안 수신컬럼 `purchase_fee_*`(확정=car-erp). ⚠️ **car-erp 먼저 배포** 후 board v4 전송(그전엔 신규 3필드 무시됨=무해, 단 ERP 에 매도비 계좌 안 꽂힘). 로그 `selling_fee_payee_account`도 `***` 마스킹. board 입력=listings(추가·편집)+auction 드로어.
- **버전·전방호환**: `contract_version` 명시. **양쪽 모두 "모르는 필드는 무시"** → 필드 추가해도 안 깨짐.
- **로그**: 모든 시도(성공/실패) = `integration_events`(outbound/car_erp/purchase_sync) append-only. **`payee_account` 는 로그에 `***` 마스킹**(전송 본문엔 실값). board_audit_logs 와 별개.
- **안전밸브**: `services.car_erp.base_url`/`hmac_secret` 미설정 시 Job no-op → car-erp 수신측 배포 전 board 를 master 배포해도 안 터짐(아무것도 안 보냄).
- **보안경계**: RRN/전화 미포함. ⚠️ **서류(차량등록증)는 v2 부터 `attachments` 로 포함** — board "서류 미보유" 원칙의 **범위한정 예외**(2026-06-22 Jin: 차량등록증은 주소·RRN **마스킹본**이고 car-erp 가 NICE 로 권위데이터 재등록 → board 보유분은 참고사본, 실행파일만 차단). `payee_account` 는 board 암호화 보관(§6e), 전송은 HMAC+HTTPS 한정 → car-erp 매입탭 정산계좌로 수신.
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
- **진행카드**(2026-06-19): shippable item `shipping_status`(none/requested/in_progress)+`requested_method` 으로 분기 — requested/in_progress = 맨 위 묶음카드(바이어+방식+상태, 요청됨=amber/진행중=blue), none = 아래 선택 UI. car-erp 가 요청차 목록 유지(916caa4) → 활성.
- **🟢 운영 배포 + 시크릿 (2026-06-19, master aed439e)**: 포털·매물자동채움·다크사이드바 운영 라이브. **`CAR_ERP_READ_HMAC_SECRET=ssancar2`(board·car-erp 운영 양쪽 동일, config:cache 완료)**. board→car-erp 운영 e2e finance 200 검증.
- ⚠️ **흔한 함정 = "연동 안됨" = 계정 매핑**(키 문제 아님): 포털은 로그인 영업의 `car_erp_salesman_email ?: email` 로 car-erp **활성 salesman** 조회 → 안 맞으면 **403 "조회 불가"**(빈 화면). 운영 board 계정 대부분 @board.test 라 미매칭. **`moo@board.test`(→moo@car-erp.test 매핑됨)로 로그인하면 데이터** / 다른 계정은 `/users` 에서 car-erp 영업 이메일 매핑. car-erp 활성 영업 = moo/art/leeyongbin@car-erp.test.
- **운영 서버 ops**: 같은 Lightsail(`ubuntu@52.79.200.151`), board=`/var/www/board`·car-erp=`/var/www/car-erp`, SSH 키 = Jin PC `~/.ssh/car_erp_key`. .env 는 deploy 가 안 건드림(서버 수동 + `config:cache`).

### 14-2. 재고 4분류 + §11 요청·확인 신호 (2026-08-09 배포, master `b0f875a`)

포털 탭 구성 변경: **`매입내역` → `재고`**. 구 매입내역은 `purchase_price>0` **전량조회(무필터·무페이징)** 라 단조증가했다.

- **재고 4분류** = `awaiting_payment`(지급대기) · `general`(일반재고) · `pre_ship`(선적전) · `shipped_out`(출고완료). car-erp `erp/inventory` 와 **같은 이름·같은 정의**(board 가 분류를 발명하지 않는다). 엔드포인트 = `GET /api/internal/board/inventory?category=…`.
  - **기본 탭 = 지급대기** — [입금요청] 대상이 그 집합이기 때문(§11-16 참조).
  - **`shipped_out` 만 영원히 누적** → 기본 30건 + `[더 보기]`(limit 증가, offset 아님 — 중복·누락 없이 다시 받음). 나머지 3분류는 유한(영업당 20~50대)이라 전량.
  - **검색은 ERP 로 넘긴다**(`search=`) — 최근 30건만 받아놓고 board 에서 거르면 옛날 차를 영영 못 찾는다.
    - 훑는 칸 **8가지**(2026-08-12 기준) = 차량번호 · 브랜드 · 차종 · 차대번호 · 수출신고번호 · 선박명 · 컨테이너번호 · **바이어명**.
      ⚠️ board placeholder 를 **실제보다 좁게 쓰지 말 것** — 오래 "차량번호·차대번호·선박명"이라 써놔서
      브랜드·컨테이너번호로도 되는 걸 아무도 몰랐다. 칸을 늘리려면 **ERP 에 요청**하면 되고(양쪽 화면에 같이 들어감),
      한쪽에만 추가하면 car-erp CI(`BoardInventoryApiTest::test_search_columns_match_the_screen`)가 막는다.
    - ⚠️ 바이어 검색은 **`buyer_id`(판매 바이어) = 행에 찍히는 그 값**이다. `export_buyer_id`(통관 바이어)가 아니다 —
      그걸로 훑으면 **A 로 표시된 행이 B 를 쳤을 때 나온다**(board 인계서가 실제로 틀리게 지목했고 ERP 가 바로잡았다).
    - 「일반재고」는 바이어가 없다(`sale_price ≤ 0` = 투기매입) → 거기서 바이어로 치면 **0건이 정상**.
- **§11 요청·확인 신호**(카톡 대체) = `POST/GET /api/internal/board/requests`. 매입 행마다 **[계약금]/[매입잔금]**(차량 1대 + 금액), 판매 바이어 블록에서 차량 체크 후 **[판매대금확인]**(바이어 1 + N대). 권위 = car-erp `board-portal-api.md §11`.
  - 💰 **매입은 금액을 싣는다(§11-2 개정, 2026-08-11)** — 받는 사람이 얼마를 보낼지 알아야 한다. ERP 는 **표시 전용**으로만 보관(🚫 회계 컬럼 반영은 여전히 금지 = §11-5). **판매대금확인엔 금액 없음**(Jin 확정 — 분리는 입금요청만). 회귀 테스트 = `test_sale_confirm_payload_carries_no_amount`.
  - ⚠️ **계약금·잔금은 별개 `type`**(`purchase_deposit`/`purchase_balance`)이다. subtype 한 개로 뭉치면 **ERP 멱등키 `(vehicle_id, type)`** 에 걸린다 — 구 `purchase_payment` 는 "매입 미지급 0" 이면 소멸이라 계약금을 지급해도 잔금이 남아 안 닫히고, 그 차의 잔금 요청이 `already_open` 으로 **조용히 버려진다**. 그래서 계약금은 **수동확인으로만** 닫는다. 계약 문자열 = `CarErpReadService::REQ_*` 상수 한 곳. 구 `purchase_payment` 칩은 **계속 그린다**(이력이 사라지면 재요청을 부른다).
  - ⚠️ **금액칸은 `reqAmount[vehicle_id]` 로 차량 키잉** — `reqNote[$buyerId ?? 0]` 처럼 한 칸을 공유하면 A 행 금액이 B 행 요청으로 나간다(틀린 금액이 그대로 송금되는 사고). 가드 = `test_purchase_request_amount_is_keyed_per_vehicle`.
  - 🚫 **금액 자동계산 금지** — 잔금 = 매입가 − 계약금 자동채움 ❌. 계약금은 board 가 알 수 없다(§14-5 와 같은 원칙).
  - **알림톡은 ERP 가 보낸다**(board 발송 코드 0). 번호를 가진 쪽이 ERP 이고, board 가 보내면 "요청은 skip 됐는데 알림톡은 갔다"가 생긴다. 근무시간 밖(평일 17:30 이후·주말·공휴일) 라우팅도 ERP 판정 — board 가 미리 판정해 힌트를 실어 보내지 말 것(판정 지점이 갈리면 어긋난다). 인계 = `meetings/handoff-carerp-payment-request-split.md`.
  - **상태 칩은 ERP 집계값 그대로**(open/partial/done/cancelled). board 가 재계산·완료 coerce 금지.
  - 바이어 혼합은 **ERP 422 `buyer_mismatch` 가 진짜 보증**이다. board UI(바이어 블록 안에서만 체크)는 실수 방지용 — `toggleReqVehicle` 은 공개 Livewire 액션이라 조작된 호출로는 섞을 수 있다.
- ⚠️ **"board 화면 필터"는 트래픽을 못 줄인다** — ERP 가 이미 전량을 조회·전송한 뒤 감추는 것뿐이다. **실제로 줄이려면 ERP 쿼리 파라미터**로 보내야 한다. 예: 판매내역 「거래완료 숨기기」는 `exclude_status=거래완료` 로 나가고, ERP 가 `whereNotIn` 으로 거른다. `progress_status_cache` 는 **인덱스가 있는 캐시 컬럼**이라 이 필터가 실제로 행을 줄인다(뱃지 표시 비용도 행당 문자열 하나, 추가 쿼리 0).
- **진행상태 뱃지 = `progress_status_cache` 그대로**(판매중·판매완료·거래완료·매입중·매입완료·통관중·말소완료…). **추리거나 재명명 금지**(Jin 2026-08-09) — 갈리면 "ERP엔 있는데 board엔 없다"가 된다(§11-13 서류 이름과 같은 사고).
- **칩 조회 실패를 감추지 말 것** — `GET /requests` 가 죽으면 칩이 전부 사라져 "아무것도 요청 안 함"과 똑같이 읽힌다. 탭마다 "상태 조회 불가" 한 줄(`req_chip_unavailable`). 버튼도 같은 원칙 — `vehicle_id` 없는 행은 **버튼을 없애지 말고 비활성 + 사유**.
- **성능**: 칩은 `chipMap` 으로 **렌더당 1회**만 훑는다(행×묶음×차량 → 1회). 읽기 API 응답은 **정렬이 없다(=id 순)** → `latestFirst()` 로 최신 우선(날짜 desc, 빈 날짜는 맨 뒤).

### 14-3. 로컬 e2e — board ↔ car-erp 연결 (매번 헷갈리는 지점)

- 배선: board `:8003` → `CAR_ERP_BASE_URL=http://127.0.0.1:8001`. 양쪽 dev 브랜치로 띄우면 된다.
- ⚠️ **`/portal` 은 계정 매핑이 없으면 403** — `car_erp_salesman_email ?: email` 로 car-erp **활성 salesman** 을 찾는다. board 계정 이메일이 car-erp `salesmen` 에 없으면 빈 화면(§14 함정과 동일).
- ⚠️ **board 로컬과 car-erp 로컬은 서로 다른 시드다.** board 의 `car_erp_vehicle_id`(erp#180~201)는 **운영 ERP** 를 가리키고 car-erp 로컬엔 그 id 가 없다. 시드 차량으로 버튼만 눌러보면 "동작은 하는데 우리 차가 아닌" 테스트가 된다.
  → **진짜 사슬을 만들려면**: board `/listings` 등록(셀프검차매입) → `/auction` 구매확정(won) → 연동 B 가 car-erp 에 차량 생성(`car_erp_vehicle_id` 회신) → 그 차가 `/portal` 재고에 같은 차량번호로 뜬다 → 거기서 [입금요청]. `QUEUE_CONNECTION=sync` 라 저장 즉시 발사된다.
- ⚠️ **스펙 문서와 구현이 갈리면 구현이 권위**(§14 canonical 과 같은 교훈). 실제 사례: §11-3 문서는 `batch_id` 가 항상 발급된다고 썼는데 구현은 `purchase_payment` 일 때 **null**, `skipped[]` 키도 `forbidden`=`vehicle_id` / `already_open`=`vehicle_number` 로 **갈린다**. 클라이언트는 구현에 맞추고, 문서 정정은 그쪽 세션에 인계.

### 14-4. 운항 상태 — 진행상태와 **직교하는 축** (§12, 2026-08-09)

권위 = car-erp `docs/integration/board-portal-api.md §12`. 판정은 ERP `Vehicle::scopeSailing` **단일출처**.

- **한 축이 아니다.** 선적일+ETA 가 둘 다 있으면 배가 떴고, ETA 가 미래면 `in_transit` / 지났으면 `arrived`. **진행상태를 가로지른다**(실측: 판매중·통관중·선적완료·거래완료에 흩어져 있음). 🚫 `progress_status` 자리에 합치거나 승격시키지 말 것.
- **필드 5개**(`/sales`·`/inventory` 공통) = `sailing`(영문 키, **분기용**) · `sailing_status`(한글 라벨, **출력용**) · `vessel_name` · `shipping_date` · `eta_date`. 둘 중 하나로 다른 하나를 만들어내지 말 것.
- ⚠️ **「도착예정」을 「도착」으로 줄이지 말 것** — ETA 가 지났다는 뜻일 뿐 **입항 확인이 아니다**(포워더 소스가 ERP 에 없다). 영업이 바이어에게 "도착했다"고 전하면 지연 시 그대로 클레임. 칩은 ERP 라벨을 그대로 찍어 구조적으로 안전하지만, **board 소유 문자열(필터 pill 등)은 lang 테스트로 핀 고정**(`test_board_never_labels_sailing_as_arrived`).
- **필터는 `/sales` 만** — `sailing=in_transit|arrived`, `exclude_status` 와 **동시 적용**(직교라서). 재고에는 얹지 말 것(§11-18). 영문 키만 — 쿼리는 HMAC canonical(ksort+`http_build_query`) 대상이라 한글 라벨을 실으면 서명이 깨진다.
- **ERP 미배포 degrade** = 행에 `sailing` 키가 있는지로 판정해 없으면 필터 UI 통째 숨김. 단 필터가 걸린 동안은 항상 노출(§11-19).
- **바이어 합계는 재계산 금지** — 운항 필터는 `/sales` 행만 줄이고 `/by-buyer` 헤더는 전체 기준이다. 맞춰 다시 계산하면 그건 board 가 만든 숫자다(진행상태 재명명과 같은 사고). 대신 0대 바이어 블록만 접고 "합계는 전체 기준" 한 줄.
- **색 = 운항중 `badge-blue` / 도착예정 `badge-teal`** — car-erp 차량목록 구현 그대로. §12 스펙 *텍스트*는 "도착예정=초록"이라 썼지만 **구현은 teal** 이다. 초록(`badge-green`)은 진행상태 「거래완료」가 이미 쓰는데 두 뱃지가 **같은 칸에 나란히** 붙기 때문. 문서와 구현이 갈리면 구현이 권위(§14-3). 두 클래스 모두 board CSS 에 이미 있어 `npm run build` 불필요.
- **선박별 묶기는 안 만들었다** — ERP `/inventory?search=` 가 **`vessel_name` 도 서버에서 검색**한다(실측 확인). 검색창에 선박명을 치면 같은 배에 실린 차가 나오므로 board 에 그룹핑 UI 를 따로 두지 않는다. `/sales` 엔 검색이 없어 거기선 미제공.

### 14-5. 셀프검차매입 금액 — 출처별로 **다른 칸·다른 공식** (2026-08-10 Jin 확정)

셀프검차매입은 `/inspection`(최종금액)·`/forwarding`(견적)을 둘 다 건너뛴다 → **파생계산의 근거(할인율·차감액)가 없다.**
그래서 `/auction` 드로어의 금액칸이 **origin 으로 갈린다.**

| 출처 | 칸 | 바이어 금액(=`final_price`) |
|---|---|---|
| 셀프검차매입 | 차값 · **매도비** (항상 KRW) + 판매가 · 환율 · 운임비 (**견적통화 기준**) | 판매가 × 환율 (**적은 값 그대로**) |
| 그 외 | 차값 · 할인율 · 차감액 · 배송(선택) | `totalKrw()` 파생계산 |

- ⚠️ **매도비는 셀프검차에서만 차값에서 뺀다.** 그 경로는 매도비가 **차값에 포함된 금액**이라 빼야 합계가 보존된다
  (13,600,000 → 매입가 13,160,000 + 매도비 440,000). 안 빼면 **매도비가 두 번 잡혀** car-erp 부가세마진
  (`매입가 × 0.09`)까지 부풀어 오른다. 다른 출처는 매도비가 **회사 부담 별도**라 빼면 매입가가 깎인다.
  판정은 `PurchaseListing::purchasePriceKrw()` / `sellingFeeKrw()` **단일 출처** — Job 이 직접 계산하지 말 것.
- **새 컬럼 2개** = `selling_fee`(매도비 금액, null=기존 `config('board.sales_fee')` 고정값) ·
  `sale_price`(판매가, **원화 아님** — `offer_currency` 기준 raw). 둘 다 null 이면 **기존 동작 그대로**라 다른 출처 무영향.
- ⚠️ **운임비 컬럼이 둘이다** — 기존 경로 = `shipping_usd`(USD 정수·선택형), 셀프검차 = `transport_fee`(**판매통화** decimal).
  하나로 합치면 안 된다: `shippingKrw()` 가 USD 환율을 곱하도록 되어 있어 거기에 EUR·KRW 금액이 들어가면
  `totalKrw()` 가 조용히 틀어진다. 셀프검차 저장 시 `shipping_usd` 는 **null 로 비운다**(둘 다 있으면 어느 게 진짜인지 갈린다).
  car-erp `transport_fee` 도 판매통화 기준이라 셀프검차 값은 **환산 없이 그대로** 나간다.
- **통화 축이 둘이다** — 차값·매도비 = 항상 KRW / 판매가·환율·운임비 = 견적통화(KRW·USD·EUR pill).
  라벨에 통화를 반복해 붙이지 않고 **pill 하나를 단일 표시**로 삼는다(2026-08-10 Jin) — 대신 어느 통화 기준인지
  한 줄 안내(`self_currency_hint`)를 반드시 남길 것. 안 남기면 숫자만 보고 통화를 오해한다.
- 🔒 **필수 락 4개**(셀프검차 전용, 2026-08-10 Jin) — 하나라도 비면 **구매확정이 막힌다**:
  **차값** · **판매가** · **견적통화** · **환율**(원화 아닐 때만).
  - 차값 없음 → car-erp 422(`required_without`).
  - 판매가 없음 → car-erp 가 판매 pre-fill 을 통째로 보류(`sale_price>0 && rate>0`) → **ERP 판매탭이 빈 채로 생긴다**.
  - ⚠️ **통화·환율 락은 실측으로 찾은 조용한 오염이다.** 통화 pill 을 KRW 로 **미리 골라두면** USD 판매가를 적고
    통화를 안 눌러도 통과해 **8,590 USD 가 8,590원**으로 ERP 에 박힌다(실제의 1/1400, 경고 없음).
    통화 락만 걸면 두 번째 구멍이 남는다 — 환율칸이 `1` 로 **미리 채워져** 있으면 USD 를 골라도 `exchange_rate=1` 로 박힌다.
    → 셀프검차는 **통화·환율 둘 다 미리 채우지 않는다**(빈 값으로 시작). 빈 값이어야 락이 잡는다.
  ⚠️ 이 락은 **셀프검차에만** 건다. 다른 출처는 견적 씬에서 채워지므로 여기서 막으면 기존 흐름이 죽는다.
- 🚫 **자동계산이 아예 없다**(2026-08-10 Jin) — 통화를 눌러도 환율이 안 바뀌고, 환율을 적어도 판매가가 안 따라오고,
  `판매가 × 환율` 로 `final_price` 를 만들지도 **않는다**. 전부 "적은 값 그대로"다. 빈 칸일 때 KRW=1 폴백만 남겼다.
  ⚠️ 그래서 셀프검차는 **`final_price` 가 비어 있다** → Job 이 `offerAmount()`(final_price 기반)를 쓰면 판매 통화·환율이
  안 실리고, car-erp 는 `sale_price>0 && rate>0` 일 때만 판매 pre-fill 을 저장하므로 **통째로 보류**된다.
  → Job 은 셀프검차일 때 `offer_currency`·`offer_rate` 를 **컬럼에서 직접** 읽는다.
- ⚠️ **매도비 > 차값이면 저장 거부**(`lte:car_cost`). 통과시키면 매입가가 `max(0,…)` 으로 깎여 **0원짜리 차**가
  ERP 원장에 생긴다(car-erp 검증도 `min:0` 이라 그냥 통과한다). 단 **차값이 비었을 땐 이 규칙을 걸지 않는다** —
  진짜 원인(차값 누락)을 가리고 엉뚱한 칸을 고치게 만든다.
- 매도비 기본값 = `config('board.sales_fee')`(440,000) 미리 채움. ⚠️ **차값에 비례하지 않는 상수**라 저가 차량에선
  비중이 크다(160만원 차의 27.5%). 영업이 안 고치면 그대로 나간다 — 저가 매입이 늘면 기본값 재검토.

### 14-6. 포털 차량 보조정보 (차대번호·브랜드/차종, 2026-08-10 Jin)

"차량번호가 보이는 곳이면 차대번호·브랜드/차종도 같이" — 단일 partial `_vehicle-meta.blade.php`(차량번호 아래 작은 회색 줄).

- ⚠️ **값은 전부 car-erp 가 준다.** board 에 소스가 없다 — 포털 행은 ERP 차량이고 `purchase_listings.vin` 이
  대응되는 건 극소수다(2026-08-10 실측: 연동된 차 4대뿐). **읽기 API 가 안 보내면 board 는 아무것도 못 한다.**
- 적용 탭 = **미수금 · 재고 · 판매내역 · 선적요청** 4개. **정산내역은 제외** — 바이어별 집계만 렌더하고
  차량 행이 아예 없다(`'sales','settlements' => byBuyer()`). ⚠️ 탭을 세기 전에 **렌더 블록을 열어볼 것**.
- 필드 = `vin` · `brand` · `model_type`. **각각 독립 degrade** — 없으면 그 조각만 빠지고, 셋 다 없으면
  **아무것도 안 그린다(대시도 금지)**. 빈 줄이 늘어서면 "정보가 없는 차"로 오해된다.
- 선적요청은 호출부가 **4곳이고 데이터 형태가 다르다** — 묶음 pill·변경요청 행(`bundles.vehicles[]`),
  계획 편집(`$vnoMap`, **차번호 문자열만 담고 있어 행 자체를 담는 `$vrowMap` 을 따로 만들었다**), chipMap(렌더 아님).
  수신측도 컨트롤러가 **2개**다(`InternalPortalController` + `ShippingRequestController`) — 하나만 고치면 절반만 된다.
- ❓ **VIN 노출은 car-erp §3 PII 화이트리스트 판단**이다. `inventory` 가 `nice_reg_vin` 을 **검색에만** 쓰는 것과
  응답에 emit 하는 건 노출면이 다르다. 인계 = `meetings/handoff-carerp-portal-vehicle-meta.md`.

### 14-7. 매입 등록 락 — 연동 B 의 유일한 상류 차단점 (car-erp §4-0, 2026-08-10)

car-erp 의 매입 락 4겹은 전부 **차량관리 화면 `save()` 안**이라, 연동 B(`purchase-sync`)는 `Vehicle::create` 직행이라
**어느 락도 안 거친다**. 수신 시점 거부도 답이 아니다 — board 는 이미 `won`(낙찰 = 돈이 나간 뒤)에 보내므로
거부하면 **회사가 소유한 차가 ERP 에 없는 상태**가 될 뿐이다. → 막을 수 있는 곳은 **영업이 바이어를 고르는 상류** 하나뿐.

- 판정은 `GET /buyers` 가 동봉하는 **`purchase_locked` 를 그대로 신뢰**한다(ERP `PurchaseRegistrationGate` 단일출처).
  🚫 조건을 board 에 옮겨 적지 말 것 — 갈리면 영업은 board 에서 "가능"을 보고 **돈을 쓴 뒤** ERP 에서 막힌다.
- 🚫 **`basis` 와 `reference` 를 나란히 그리지 말 것.** ratio 모드에서 `available_krw`(보증금 여력)는 락과
  **분모·분자가 아예 다르다** — "여력 0원인데 등록 가능"·"락인데 여력 1천만"이 **둘 다 정상**이다. 근거는 `basis` 하나뿐.
- `mode='off'` 또는 `basis.kind=null`(토글 OFF·신규 바이어) → **아무것도 안 그린다**. 빈 배지가 더 헷갈린다.
- `basis.current/limit` 은 **숫자로만** — JSON 이 `20.0` 을 `20` 으로 준다.
- **문구는 "불가"가 아니라 "ERP 관리자 승인 필요"** — 락은 절대 규칙이 아니다(ERP 에서 사유를 적으면 1회 통과).
- ⚠️ **차단은 구매확정 시점에 재조회**한다(드로어를 열어둔 사이 풀렸을 수도, 걸렸을 수도 있다).
  조회가 **degrade 면 막지 않는다** — 여기서 막으면 ERP 장애가 board 의 매입 마감을 통째로 세운다.
- 🔒 **바이어는 필수다**(2026-08-10 Jin) — 안 고르면 연동 B `buyer_id` 가 null 로 나가 **판정할 대상이 없다**.
  즉 "안 고르면 통과"가 되어 락에 구멍이 남는다. **바이어 필수화가 락의 전제**다.
- **락 걸린 바이어면 구매확정 버튼 자체가 비활성**이다(눌러보고 막히는 것보다 낫다). 서버에서도 한 번 더 본다 —
  드로어를 열어둔 사이 상태가 바뀔 수 있고 Livewire 액션은 직접 호출될 수 있다. 유찰/취소는 그대로 눌린다
  (락은 매입 **등록**을 막는 것이지 취소를 막는 게 아니다).
- 검사 순서 = 금액 → 바이어 → 락. 락 조회는 **ERP 호출**이라 맨 뒤(폼이 이미 틀렸으면 부를 이유가 없다),
  바이어는 금액 뒤(먼저 두면 금액 오류를 가린다).
- ⚠️ **연동 B 가 보내는 건 「차량 등록 정보」뿐이다**(Jin 2026-08-10). 등록 이후 단계(당사자·회계컬럼·C4/C5 등)는
  **전부 ERP 안에서 ERP 화면 게이트를 탄다** — board 가 우회시킬 수 있는 게 아니다. board 가 미러할 락은
  **등록 시점 하나**(미수·무담보)뿐이고, 그게 이 절의 전부다. 🚫 ERP 내부 단계의 락을 board 로 끌고 오지 말 것.

### 14-8. 선적 계획 — 후보 확대 + 포워딩사 + 컨테이너 운임비 (2026-08-12, car-erp master `94a59c3`)

**미완납 차도 미리 묶어두는 화면**이 됐다. 목적 = 돈 들어오기 전에 **서류를 미리 준비**(진행은 입금 후).
후보 조건 = `sale_price>0` + export + **반입지·B/L 없음** + open 묶음 아님(구 조건 = `판매완료` = 완납).

- ⚠️ **출고일(`warehouse_out_date`)이 찍힌 차도 후보에 온다** — 출고일과 반입지는 **독립된 축**이라 한쪽만
  찍힌 차가 흔하다(heymanerp 실측: 구 후보 29대 대다수). "출고 전만 온다" 전제로 화면을 짜면 어긋난다.
  ERP 가 출고일로 거르면 넓히랬는데 **좁아지는** 배포가 됐을 상황이었다.
- 🚨 **`unpaid_krw = null` 은 완납이 아니다** — 환율 미입력이라 **판정 불가**라는 뜻이고 ERP 도 그때
  `fully_paid=false` 를 준다. **0 으로 바꿔 그리면 가짜 완납**(cash_audit 계열 사고). 칩 = 「환율 미입력」 별도 표시.
  가드 = `test_portal_plan_marks_unpaid_and_never_fakes_paid_without_fx`.
- **운임비는 CONTAINER 에서만**(Jin). RORO 로 보내면 ERP 가 **조용히 버린다(에러 아님)** → board 가 먼저 뺀다.
  방식을 되돌려도 값은 `desired` 에 남긴다(다시 CONTAINER 로 바꾸면 살아나게).
- **1/N 은 합계가 안 맞을 수 있다** — 몫 = 총액 ÷ 대수(내림), 나머지는 최소 `vehicle_id` 한 대. **이미 값이 있는
  차는 건너뛴다**(관리가 ERP 에서 고친 값 보호). 🚫 화면에서 **"총액이 그대로 기록된다"고 안내하지 말 것**.
- **포워딩사는 board 가 고르기만** 한다(신규 생성 없음 — 오타·중복이 지급 명부를 오염시키는 경로를 안 만든다).
  ⚠️ **`vehicles.forwarding_company_id` = ERP 원장**이고 `forwarding_missing` **액션 큐 조건**이라,
  board 가 채우면 **관리자 할 일에서 그 차가 사라진다**(Jin 승인). ERP 는 **값이 실제로 바뀔 때만** 반영하므로
  **"보냈는데 차량 값 그대로"가 정상**이다(관리 수정본을 board 재전송이 안 되돌린다).
- 명부 조회 실패 = **드롭다운 통째로 숨김**. 빈 목록으로 두면 "포워딩사가 하나도 없다"로 읽힌다.
- ⚠️ `syncBundles` 는 끝에서 `load()` 로 **ERP 응답으로 다시 그린다** → 편집값은 `GET /bundles` 가
  돌려주는 것만 살아남는다. 그래서 `forwarding_company`·`transport_fee_usd_total` 복원이 회귀 포인트
  (`test_portal_plan_restores_forwarder_and_freight_from_bundles`). 안 살면 다음 sync 가 **빈 값으로 덮는다**.
- **surrender 미수 가드는 안 만든다**(Jin 2026-08-11) — 선적 계획은 계획일 뿐이고 B/L 요청은 별도 행위다.

### 14-9. 딜러 차량 첨부는 **올리는 곳과 보는 곳이 다르다** (2026-08-12)

- **올리는 곳 = `/auction`(구매·경매) 드로어 하나뿐**이다. `/listings` 에도 업로드 *로직*(`eSalesFiles`·
  `storeSalesFiles`·`deleteSalesAttachment`)이 있지만 **렌더부에 UI 가 없다** — 만들다 만 상태다.
  ⚠️ 로직이 있다고 "그 화면에서 올린다"고 말하지 말 것(실제로 그렇게 잘못 안내했다).
- **보는 곳 = `/listings`(매입예정) 편집 드로어** — 읽기 전용 그리드. 이유: `/auction` 목록은
  `accepted·won·failed` 만, 첨부 블록은 **`accepted·won` 에서만** 그린다 ⇒ 연동 B 로 넘어가 **`synced` 가
  되는 순간 board 어디서도 못 봤다**(`failed` 도 마찬가지). 매입예정 목록은 **전 상태 전량**이고
  `openEdit` 에 **상태 가드가 없어** synced 행도 열린다 — 본인 차를 전 상태로 여는 유일한 화면.
- 🚫 **보는 곳에 삭제·업로드를 붙이지 말 것** — `won` 이후엔 같은 첨부를 **ERP 도 갖고 있다**.
  board 에서 지우면 양쪽이 조용히 갈리고 board 가 더 이상 유일한 권위가 아니게 된다.
- **URL 은 `InspectionPhoto::url()`**(모델 accessor). ⚠️ 같은 로직이 화면 컴포넌트에 `photoUrl()` 로
  **3벌**(auction·forwarding·inspection) 더 있다 — **새 화면은 accessor 를 쓰고 4벌째를 만들지 말 것**.
  디스크가 로컬(public)/운영(s3)로 갈려 경로를 손으로 조립하면 깨지고, presigned 는 **캐시로 문자열을
  고정**해야 한다(렌더마다 재서명하면 영상 재생이 리셋).
- **`/manage`(관리)엔 아직 없다** — 관리자가 첨부를 보려면 ERP 를 연다(2026-08-12 Jin: 1번만 하기로).

## 15. 사내 Notion 업무가이드 발행 + 허브 네비 표준 (Jin 지시 — "항상 이 상태로")
> 사내 Notion "사내 업무 가이드" 갱신 = **MCP 아님**, 자체 스크립트 `scripts/notion-guide-publish.php`(Notion REST 직접). 토큰 = Windows **User env `NOTION_TOKEN`**. 세션이 토큰 등록 전에 켜졌으면 `getenv()` 못 잡음 → PowerShell 인라인 주입: `$env:NOTION_TOKEN=[Environment]::GetEnvironmentVariable('NOTION_TOKEN','User'); php scripts/notion-guide-publish.php --apply`. **발행=라이브 즉시반영** → apply 전 인자 없이 dry-run 으로 블록수 확인.

**허브 구조**: 허브 `사내 업무 가이드` → 섹션 child_page **`🛒 매입보드 (BOARD)`** / **`🏢 ERP (car-erp)`** → 각 섹션 하위 가이드 child_page + **허브 메인에 섹션별 네비게이션**(섹션 child_page 바로 아래 `bulleted_list_item` 안 **page-mention**).
- board 스크립트 `$targets` = `전체 워크플로우 / 영업 / 검차 / 관리 / 에러·락 대처`(blocks_workflow/…/blocks_troubleshoot). 본문 하드코딩 → `--apply` 로 블록 통째 교체(clearBlocks+append, child_page 보존).
- ⚠️ **타깃 발행 = line 33 `$only` 화이트리스트에 제목 있어야** — 없으면 매칭실패→$only 빈배열→**전 페이지 재발행**. 공백·`·` 제목은 따옴표(`--apply "전체 워크플로우" "에러·락 대처"`).

**⭐ 허브 네비 정리 표준 (2026-07-04 Jin — "항상 이 상태, 추가분은 추가")**:
- 각 섹션(매입보드·ERP) 네비 순서 = **🔄 전체 워크플로우(맨 위) → 실무 페이지들(그대로) → ⚠️ 에러·락(맨 아래)**. 양쪽 대칭. 현재 board=5링크, ERP=6링크(공통/재무/수출통관/관리(통합)+워크플로우+에러).
- 하위 페이지 **추가 시 허브 네비에 mention bullet 만 append**(PATCH `/blocks/{hubId}/children` + `after`=섹션 blockId 또는 마지막 nav bullet). 기존 정상 링크는 안 건드림. 멱등(이미 있으면 스킵).
- 🚫 **순서 바꾸려고 페이지 삭제·재생성 금지** — page ID 바뀌어 **허브 mention 전멸**(2026-07-04 board 3링크 다 깸 → 복구). 순서변경 = Notion 드래그(무손실). Notion API 는 기존 블록 move 미지원.
- 복구/추가 헬퍼 패턴(일회성, scratchpad): 허브→섹션 child_page 구간의 bullet 을 새 페이지 mention 으로 교체(board) / 새 페이지만 append(ERP). ERP 가이드 페이지 자체는 car-erp 세션 소관이나 **허브 네비(공유 인프라)는 Jin 지시로 board 세션이 정리 가능**.

### 14-10. super 는 남의 포털에서도 **대신 실행**한다 (2026-08-18 Jin — 정책 변경)

> Jin: "시스템관리자는 다 되게 erp처럼 해주면 안되나? 꼭 매핑을 해야해?"

- **바뀐 것**: `/portal` 의 `isViewingOther()` **쓰기 차단 8곳을 걷어냈다**(sync·B/L요청/취소·변경요청·§11 신호·
  서류 다운로드·서명요청) + **선적 계획 화면을 통째로 조회전용 문구로 대체하던 렌더 분기**와
  묶음 액션 `@unless` 블록도 제거. 그 화면이 비어 보이던 게 "선적요청이 막힌다"의 정체였다.
- ⚠️ **`isViewingOther()` 가 true 면 이미 super 다** — `viewingUser()` 가 `isSuper()` 일 때만 non-null 이라,
  이 차단은 **처음부터 super 만 겨냥한 것**이었다. 그래서 "super 예외"가 곧 "제거"다.
- 🚨 **요청은 그 영업 명의(`salesman_email`)로 car-erp 에 간다** — ERP 관리는 **그 영업이 한 것으로 본다**.
  남은 안전장치는 **화면 배너 하나**(`viewing_other` 가 명의를 밝힌다)뿐이다. 문구를 약하게 고치지 말 것.
- ⚠️ 특히 **`syncBundles` 는 선언형 전체전송**이라, 남의 포털에서 누르면 그 영업의 requested 묶음이
  **자동취소**될 수 있다. 로딩 degrade 가드(`flash_sync_blocked_degraded`)가 유일한 방어선이므로 **그건 유지**.
- **매핑은 이제 선택**이다. `car_erp_salesman_email` 이 없어도 super 는 이름 클릭으로 남의 포털에서 다 한다.
  (매핑하면 본인 포털에도 그 영업 데이터가 뜬다 — 테스트엔 편하지만 "누구 것인지" 헷갈릴 수 있다.)
- 죽은 문구 3키(`flash_view_only_ship`·`flash_view_only_docs`·`req_blocked_viewing`) 제거. 되돌릴 땐 git 이력에서.
- 가드 = `test_portal_super_can_act_on_behalf_of_other`(구 `..._is_view_only` 를 **반대로 다시 쓴 것** — 지우지 말 것).

### 14-11. 판매계약서·프로포마·전자서명은 **선적 계획에서도** (2026-08-18 Jin)

- `downloadDocs(vehicleIds, method, kind)` · `requestSignature(vehicleIds, batchId?)` 는 **차량 id 기반**이고
  `batchId` 가 옵셔널이라 **묶음(sync) 없이도 발급된다** → 선적 계획(편집상태 `desired`)에서 그대로 호출.
  공용 partial = `_sales-docs.blade.php`(계획·묶음 양쪽이 같은 것을 include — 중복 금지).
- **묶음 화면에서 빼지 않았다**(Jin 은 "계획에서 되어야 한다"고만 했다). 이유 = **착수(in_progress)·완료 묶음은
  계획에 안 뜬다**(`desired` = `requested` 만) — 묶음에서 빼면 그 차들의 계약서·서명을 뽑을 자리가 사라진다.
- **선적 4종(`roro_` · `container_` 접두)은 묶음에만** 둔다 — 실제 선적 단계 서류다.
- 차를 담기 전(빈 묶음)엔 안 그린다 — 대상 `vehicle_ids` 가 없어 눌러도 의미가 없다.
- ✅ **sync 전 차량 발급은 정상**(car-erp 회신 2026-08-18) — 서류·서명 둘 다 **차량 id + 바이어** 기준이고
  `shipping_requests` 를 전혀 안 본다. ERP 가드 = `test_document_issues_without_any_shipping_request_row`.
- 🚨 **ERP 가 422 가드를 새로 넣었다**(그 "빈 서류" 우려가 실재했다 — 바이어 전부 null 이면 「1바이어」 검사를
  통과해 **공란 계약서가 200 으로 나갔고**, 판매가 검사는 아예 없었다):
  `No buyer: {차량번호}` · `No sale price: {차량번호}` · `Mixed buyers` · `Mixed currencies`.
  **전 type 공통(선적 4종 포함), 한 대만 비어도 묶음 전체를 막는다.**
- 🚫 **board 는 비활성 조건을 추측하지 않는다** — 판정은 ERP 단일 출처, board 는 **422 를 원문으로 갈라 안내**만 한다
  (`docBlockedReason()`). 전부 "동일 바이어"로 뭉뚱그리면 영업이 엉뚱한 곳을 고친다(sales_contract 403 을
  "동일 바이어"로 안내하던 사고와 같은 형태). 가드 = `test_portal_docs_422_reasons_are_distinguished`.
- ⚠️ **실패 응답 본문을 버리지 말 것** — `CarErpReadService` 의 `document()`·공통 `post/get` 이 실패 시 `body=null`
  이라 **사유가 board 에 도달하지 않았다**. `message` 키로 살린다(300자). ERP 는 **어느 차량인지까지** 준다.
- ⚠️ PHP 주석에 `roro_*/container_*` 처럼 쓰면 `*/` 가 **주석을 조기 종료**해 파스 에러가 난다(실제로 밟음).

### 14-12. 판매가를 **나중에 채우면** ERP 에 안 간다 (미해결 — ERP 개정 대기)

- 판매가·통화·운임비 **없이 보내는 건 지금도 된다** — 그 필수 락은 **셀프검차매입에만** 걸려 있고
  일반 경로는 `hasSyncableAmount`(차값 또는 final_price)만 통과하면 `won` 이 된다.
- 🚨 **하지만 나중에 채워 재전송해도 반영되지 않는다** — ERP `PurchaseSyncController` 가 `vehicle_number` 로
  기존 차를 찾으면 **첨부만 dedup 보강하고 금액은 손대지 않은 채 200** 을 돌려준다(멱등 스킵).
- 요청한 방향 = 멱등 경로에서 **빈 판매 필드만 채우기(fill-if-empty)**. ERP 원장 잠금 가드가 이미
  *"빈 값 → 첫 입력은 최초 set 이므로 통과"* 이고 주석이 **"영업이 판매가·바이어 처음 입력하는 정상 흐름 보호"** 다.
- ✅ **ERP 구현 완료**(회신 2026-08-18, dev→3사 배포 대기). `sale_date` = **수신일(`now()`)** — 신규 생성 경로가
  이미 그랬고 멱등 경로에 같은 로직을 이식했다. **board 무변경 동작**, `contract_version` 상향 없음(v3 필드 그대로).
- 규칙 = ①빈 칸만 ②이미 값 있으면 **절대 안 덮음**(`already_set`) ③판매 3종+`sale_date` 는 **세트**, 환율 없으면
  통째 보류(`missing_exchange_rate`) ④컨사이니는 차량 바이어 하위일 때만(`buyer_mismatch`).
- 🚨 **응답 `fields_filled` · `fields_skipped` 를 반드시 읽을 것** — **200 만 보고 "반영됨"으로 기록하면 안 된다**.
  `fields_filled` 가 비면 **안 채워진 것**이다(첨부 15건이 조용히 실패했던 것과 같은 부류 = `attachments_failed` 를
  실은 이유와 동일).
- ⚠️ **운임비 컬럼이 두 개다** — `transport_fee`(**판매통화**, 미수율 분모) vs `transport_fee_usd`(선적계획 메모,
  §14-8). fill-if-empty 대상은 **`transport_fee`**. USD raw 를 보내면 **미수율 분모가 부풀어 매입 락 판정이 틀어진다**.
- ⚠️ **환율을 안 보내면 판매가가 통째로 안 들어간다** → 화면은 **판매가·통화·환율을 함께** 받아야 한다(판매가만 받는 칸 ❌).
- ⚠️ `sale_date` = 수신일이면 **채권 유예 기산점도 그날**이다. 실제 판매가 한 달 전이었으면 독촉이 한 달 늦어진다
  (선적 전 한정, 관리가 ERP 에서 수정 가능).
- ✅ **board 구현 완료(2026-08-18)** — 자리 = **`/listings`(매입예정) 편집 드로어**(`_erp-resend.blade.php`).
  `/auction` 은 accepted·won·failed 라 **synced 가 빠지고**, 매입예정은 본인 차를 **전 상태로** 여는 유일한 화면이라서.
- **판매가·통화·환율을 세트로** 받는다 — 통화/환율이 없으면 **board 가 먼저 막는다**(ERP 가 조용히 보류하기 전에
  사유를 말한다). KRW 면 환율 1 자동.
- ⚠️ **Job 에 `resync` 플래그**(`SyncWonListingToCarErp::dispatch($id, resync: true)`) — `won`·`synced` 둘 다 허용하고
  이미 synced 면 **상태 전이를 안 한다**. 🚫 **예전 `/manage` 방식(`car_erp_vehicle_id=null` + `status='won'` 되돌림)을
  다시 쓰지 말 것** — 전송이 실패하면 그 상태로 남아 **차가 `/auction` 목록에 되살아난다**. manage 도 resync 로 전환했다.
- **결과는 `integration_events.response_body`** 에서 읽는다(별도 컬럼 없음). `fields_filled` 가 비면
  **초록이 아니라 노랑 + 사유**(`already_set`·`missing_exchange_rate`)를 편다.
- ⚠️ 일반 경로(셀프검차 아님)는 통화·환율을 `offerAmount()`(final_price 기반)에서 얻는데, 급하게 보낸 차는
  **final_price 가 없어 null** 이다 → Job 이 `offer_currency`·`offer_rate` **컬럼으로 폴백**한다.

### 14-13. 구매확정은 **첨부 필수** + 첨부는 status 저장보다 **먼저** (2026-08-18 Jin)

- 첨부 0건이면 **낙찰·구매확정 자체를 막는다**(`err_attachment_required`). 이번에 올리는 파일도 대상에 넣는다.
- 🚫 **"확정은 하되 전송만 보류"는 안 쓴다**(Jin 검토 요청에 대한 답) — 연동 B 는 `won` 진입 시 **1회 발사**라
  자동 재시도가 없고, 사람이 재전송을 안 누르면 **그 차는 영영 ERP 에 없다**(한참 뒤 발견 = 최악).
  확정 시점에 막으면 놓칠 수가 없다. 첨부는 매입 시점에 딜러에게 받는 것이라 "나중에 생기는 정보"도 아니다.
- 🚨 **첨부 저장을 `$l->status = 'won'; $l->save();` 보다 먼저** 한다 — status 저장이 모델 훅에서 연동 B 를
  발사하는데, 로컬(`QUEUE_CONNECTION=sync`)은 **그 자리에서 즉시 실행**돼 이번에 올린 파일이 payload 에서 빠졌다.
  운영(`database` 큐)은 워커가 늦게 집어 **우연히** 실리던 것 = 타이밍에 기대는 구조였다.
  가드 = `test_attachment_is_saved_before_sync_fires`. ⚠️ 이 가드는 **그 자리에서 업로드하는 파일**로 짜야 한다 —
  DB 에 미리 있는 첨부로 검증하면 순서를 바꿔도 통과해 **가드가 무의미**하다(실제로 처음에 그렇게 짰다).
- 테스트 픽스처 `mkListing()` 이 첨부 1건을 기본으로 붙인다(바이어 필수 때와 같은 방식). **개수를 세는 테스트**와
  "첨부 없음"을 보는 테스트는 `salesAttachments()->delete()` 로 비우고 시작한다.

### 14-14. 모바일 사이드바 중복토글 — 가드는 **window 기준**이어야 한다 (2026-08-18 재발 수정)

증상 = 모바일에서 사이드바를 펼치면 **즉시 접힌다. 새로고침하면 정상.**

- 원인 = **`wire:navigate` 가 이 레이아웃의 Alpine 인스턴스/리스너를 누적**시킨다. 같은 파일의 비프음이 겪은
  그 문제이고 거기선 `window.__boardBeepLast` 로 해결해뒀다(주석에 이미 적혀 있었다).
- 🚨 그래서 **가드를 컴포넌트 프로퍼티(`this.lastToggle`)에 두면 인스턴스마다 자기 타임스탬프를 가져
  가드가 통째로 무효**가 된다 — 2026-08-01 에 넣은 300ms 가드가 안 들었던 이유다.
  🚫 **시간을 늘리지 말 것**(그게 첫 시도였고 실패했다). `window.__boardSidebarToggleAt` / `__boardSidebarOpenedAt`.
- **"새로고침하면 정상"이 이 계열 버그의 지문**이다 — 인스턴스가 하나뿐이라서. 같은 증상을 또 만나면 상태·가드가
  인스턴스 로컬인지부터 볼 것. 가드 = `test_sidebar_toggle_guard_is_window_scoped`.
