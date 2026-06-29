# 인계 — 관리자 계정 정리 (car-erp 세션에 전달)

> 작성 2026-06-29 (board 세션). Jin 요청 = "시스템관리자 = wlsdud10074@naver.com, 최고관리자 = (회사 오너)admin 계정". board 쪽(heymanboard·ssancarboard)은 완료. **car-erp 쪽(특히 heymanerp)은 car-erp 세션에서 실행**(크로스레포 규칙: car-erp 변경은 car-erp 세션·박스에서).

## 의도된 패턴 (karabaerp = 정답 템플릿)
car-erp permission 위계: **super(시스템관리자) > admin(최고관리자) > user(+role)**.
- **시스템관리자 = super = `wlsdud10074@naver.com`** (시스템/개발 오너 = Jin 공용 super).
- **최고관리자 = admin = 회사 오너 실계정** (karaba 예: `karabaltd1004@gmail.com`).

karabaerp 현재 = 이미 정답:
- wlsdud10074@naver.com → super (시스템관리자)
- karabaltd1004@gmail.com → admin (최고관리자)

## 각 erp 현황 (board 세션이 DB 읽기로 확인, 2026-06-29)
| 인스턴스 | 박스 / DB | 시스템관리자(super) | 최고관리자(admin) |
|---|---|---|---|
| ssancarerp | 54.116.7.83 / ssancar_erp | `wlsdud10074@naver.com` ✅ | **없음** — ssancar 회사 오너 계정 필요? (Jin 확인) |
| heymanerp | 52.79.200.151 / car_erp | `admin@car-erp.test`(name "Super") ❌ | `boss@car-erp.test`(name "Boss") = admin |
| karabaerp | 15.164.91.242 / karaba_erp | `wlsdud10074@naver.com` ✅ | `karabaltd1004@gmail.com` ✅ |

## car-erp 세션이 할 일
### heymanerp (핵심 수정)
1. **`wlsdud10074@naver.com` 을 super(시스템관리자)로 추가**(없으면 생성, 비번은 Jin 확인 — 타 인스턴스는 `WLSDUD102!!`).
2. **`admin@car-erp.test`(현재 super) 정리** — 시스템관리자 보유자를 wlsdud10074 로 옮기는 게 목적이므로 admin@car-erp.test 는 다운그레이드(admin 또는 제거). 단 generic 테스트 계정이라 **그냥 제거 가능한지 Jin 확인**.
3. **최고관리자(admin) = 회사 오너** 가 `boss@car-erp.test` 가 맞는지, 아니면 heyman 회사 오너 실이메일로 교체할지 Jin 확인.

### ssancarerp
- super(wlsdud10074) 는 OK. **최고관리자(admin) 계정이 없음** — ssancar 회사 오너 실이메일을 admin 으로 추가할지 Jin 확인.

### karabaerp
- 이미 정답. 변경 없음.

## board 쪽 (참고 — 이미 완료, car-erp 가 할 일 아님)
- board 는 permission 이 super/user 2단뿐(admin 등급 없음). Jin 결정 = **이름만 구분**(둘 다 super, 표시명만 시스템관리자/최고관리자).
- heymanboard: wlsdud10074@naver.com=시스템관리자(super) 추가, admin@board.test=최고관리자(super 유지, 이름만). ssancarboard: wlsdud10074=시스템관리자(super), 별도 admin 계정 없음.
- 만약 board 도 car-erp 처럼 admin(최고관리자) **실등급**이 필요하면 그땐 board 코드변경(PERMISSIONS·UI·접근정책·i18n·3인스턴스 재배포) — 현재는 불필요로 판단(Jin A안).

## 박스 접근 (참고)
- ssancarerp/ssancarboard = 54.116.7.83, heymanerp/heymanboard = 52.79.200.151, karabaerp = 15.164.91.242. 전부 `ubuntu` + car_erp_key. car-erp 경로 `/var/www/car-erp`, DB 위 표.
