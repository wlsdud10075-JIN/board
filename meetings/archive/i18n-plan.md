# board i18n (한글/영어) — Phase 0 완료 + 다음 단계

> 2026-06-23 착수. car-erp의 locale 토글을 board에 이식. **실제 영어 사용자 있음**(Jin 확인)이라 전체 i18n 진행. car-erp는 전면 i18n(45개 lang파일), board는 i18n 제로 출발 → **Phase별로** 진행.

## 결정/배경
- 토글이 동작하려면 board 전 blade의 하드코딩 한글을 번역키로 빼야 함(car-erp는 `__('nav.menu.x')` 100%, board는 인라인 한글). 그래서 한 번에 못 하고 Phase로 쪼갬.
- **fallback_locale = en** (config/app.php·.env·.env.example): board엔 `lang/ko/validation.php`가 없어 ko 폴백 시 검증 메시지가 raw 키(`validation.required`)로 깨짐 → en 폴백으로 프레임워크 영어 문장 사용(=board 기존 동작). nav/settings는 ko/en 양쪽 완비라 영향 없음. **한글 검증 메시지는 추후 단계**(`php artisan lang:publish` + `lang/ko/validation.php` 작성).

## ✅ Phase 0 완료 (이번 세션, dev 미커밋)
**인프라 (car-erp 미러)**
- `users.locale`(string5, 기본 ko) 마이그 + `User::LOCALES=['ko','en']` + fillable.
- `App\Http\Middleware\SetLocale`(매 web요청 `app()->setLocale`, en은 locale_en_enabled 꺼지면 ko 강제) → `bootstrap/app.php` web append.
- `POST /locale`(`locale.update`): users.locale 저장, en은 기능설정 켜진 경우만(아니면 ko).
- `config/app.php` locale=ko / fallback=en.

**번역 파일** (chrome만): `lang/ko/nav.php`+`lang/en/nav.php`(group/menu/crumb/role/perm/lang/action/brand_sub), `lang/ko/settings.php`+`lang/en/settings.php`(내 설정 3페이지 + 기능설정 feature.*).

**국제화한 화면 (이만큼만 ko/en 전환됨)**
- 사이드바(`components/layouts/app/sidebar.blade.php`): 메뉴/그룹/브레드크럼/권한·역할 라벨/하단링크(업무가이드·내설정·로그아웃) → `__('nav.*')`. **상단바 ko/en 스위치 추가**(locale_en_enabled 켜질 때만, `name="locale"` form).
- 로그인 화면(auth/simple): 부제 `__('nav.brand_sub')`.
- 기능설정(admin/settings): 문구 `__('settings.feature.*')` + **영어 활성화 토글**(flux:switch, wire:model.live → updatedLocaleEnEnabled → Setting `locale_en_enabled` + 풀리로드).
- 내 설정 4종(profile/password/appearance/delete-user-form) + settings-heading + settings/layout → `__('settings.*')`.
- `User::roleLabel()` → `__('nav.role.*)` (한글 상수 폴백 유지).

**테스트**(총 125통과): 토글 영속·en전환·게이팅·미들웨어 ko강제·스위치 노출조건·**ko 검증메시지 비-raw키**.

## ✅ Phase 1 완료 — 8개 업무화면 전부 국제화 (2026-06-23, dev 미커밋→이번에 커밋)
- **공통 토대**: `lang/{ko,en}/domain.php`(status/status_live/verdict/origin/source) + `common.php`(공통 단어). 모델 `PurchaseListing` 라벨 메서드(`statusLabel/verdictLabel/originLabel`) → `__('domain.*')` 화 + `statusOptions()/originOptions()`(번역된 드롭다운 맵). → 이 메서드 쓰는 전 화면 뱃지 자동 번역.
- **8개 화면** 각각 `lang/{ko,en}/<screen>.php` + blade `__()` 교체: listings(137키)·inspection(77)·portal(92)·manage(64)·auction(42)·users(36)·verdicts(20)·audit(41). manage/audit/listings 드롭다운은 statusOptions/originOptions 사용.
- **검증**: 전 lang파일 ko/en 키 동일(12파일 645키), blade 정적 `__()` 키 누락 0(동적키 제외), 8화면 영어 렌더 200(테스트 `test_all_business_screens_render_in_english`), 전체 126통과.
- **보안**: i18n 변환 중 `{!! __(..., [user데이터]) !!}` XSS 1건(inspection conflict 차량번호) → `e()` 처리. 나머지 unescaped는 정적이거나 이미 `e()`.

## ⏭️ 남은 것 (후속)
- **auth 페이지**(login/register/forgot 등) — 현재 영어 스타터킷(`__('Log in...')` JSON키). ko 번역 미작성 → 로그인은 영어. board 한글화하려면 JSON 번역 또는 키화.
- **`lang/ko/validation.php`** — 한글 검증 메시지(현재 fallback=en으로 영어). `php artisan lang:publish` 후 한글 번역.
- 대시보드 redirect(UI문구 없음), 기타 partial 잔여 한글 점검.

## 주의
- 새 키는 **반드시 ko·en 양쪽** 추가(한쪽 누락 시 fallback=en이라 ko에서 영어 샐 수 있음).
- 상단바 스위치는 레이아웃(컴포넌트 밖)이라 토글 후 풀리로드 필요(이미 redirect 처리).
- `lang/` 디렉터리는 운영 배포 대상(코드). `.md`만 master 제외.
