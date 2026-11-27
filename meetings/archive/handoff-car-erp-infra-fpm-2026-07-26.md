# 인계 — 운영 3박스 PHP-FPM 워커 증설 + nginx 타임아웃 (2026-07-26 적용 완료)

> **수신 = car-erp Claude 세션.** board 세션에서 서버 설정을 **이미 변경했고**, 그 박스에 car-erp(ssancarerp·heymanerp·karabaerp)가 함께 올라가 있어 **ERP 쪽도 알아야 하는 사실**을 전달한다.
> 코드 변경 없음(서버 설정만). **이 문서 내용은 car-erp repo 에도 남겨줄 것** — 인프라 사실은 board 문서에만 있으면 ERP 세션이 모른다.

## 0. 한 줄 요약

3박스 모두 PHP-FPM 워커가 **기본값 5**였고 nginx `fastcgi_read_timeout` 이 **어디에도 없어 기본 60초**였다. ERP 는 **NICE 차량정보 조회**(`NiceApiService`, 등록원부+상세제원 2단 호출)를 **55초까지 기다린다** → 느린 조회는 60초에 잘려 **502 로 죽는데 NICE 과금은 발생**하던 상태. 타임아웃을 90초로 올리고, 박스 2개의 워커를 늘렸다.

> ⚠️ 용어: 여기서 말하는 건 **NICE 조회**다. ERP 차량관리의 **「원부조회」(Carmodoo, 압류·저당)와는 다른 기능**이며 그쪽은 이 타임아웃 문제와 무관하다.

## 1. 박스 매핑 (실측)

| 박스 | 올라간 앱 | RAM total/free | swap | PHP |
|---|---|---|---|---|
| `54.116.7.83` = `heymancar.com` | **ssancarerp** + ssancarboard + Django(잔존) | 7818 / 884 MB | ❌ | 8.4 |
| `52.79.200.151` = `heysellcar.com` | **heymanerp** + heymanboard | 3834 / 357 MB | ❌ | 8.4 |
| `15.164.91.242` = `karaba-erp.com` | **karabaerp** | 1907 / 183 MB | ✅ | 8.4 |

⚠️ **풀은 박스당 `www.conf` 1개** — 같은 박스의 ERP 와 board 가 **워커를 공유**한다(앱별 풀 분리 아님). ERP 가 워커를 다 쓰면 board 도 같이 멈추고, 반대도 같다.

## 2. 변경 내용

### A. nginx 타임아웃 — **3박스 전부**
`/etc/nginx/nginx.conf` 의 `http { }` 블록(13행)에 신규 추가:
```nginx
fastcgi_read_timeout 90s;
```
- 그 전엔 `nginx.conf`·`snippets/fastcgi-php.conf`·`conf.d`·vhost 어디에도 없어 **효과값 60초**였다.
- 백업 `/etc/nginx/nginx.conf.bak-20260726` · 롤백 = 백업 복구 + `sudo systemctl reload nginx`.

### B. PHP-FPM 워커 — **2박스만**
| 박스 | max_children | start / min_spare / max_spare |
|---|---|---|
| `54.116.7.83` | 5 → **14** | 3 / 2 / 6 |
| `52.79.200.151` | 5 → **10** | 3 / 2 / 5 |
| `15.164.91.242` | **5 유지** | 변경 없음 |

- **karaba 를 안 올린 이유(Jin 결정)**: RAM 1.9GB·free 183MB 뿐이라 잘못 올리면 **LIVE ERP 가 OOM**. 여기는 워커 튜닝이 아니라 **인스턴스 스펙 업그레이드**가 답이다.
- 백업 `/etc/php/8.4/fpm/pool.d/www.conf.bak-20260726` · 롤백 = 백업 복구 + `sudo systemctl reload php8.4-fpm`.
- 적용은 `reload`(graceful) — 다운타임 0. 적용 후 5개 사이트 전부 HTTP 302, 0.4~0.6초 확인.

## 3. ERP 가 알아야 할 함의

1. **NICE 조회가 더 이상 60초에 잘리지 않는다.** `NiceApiService` 의 `Http::timeout(55)` 가 이제 끝까지 살아남는다. 그 전엔 55초 근처 응답이 nginx 에서 502 로 죽고 과금만 나갔다.
2. **워커 여유가 생겼다** — 단 `54.116.7.83`·`52.79.200.151` 만. **karaba 는 여전히 5칸**이라 동시 조회 5건이면 karabaerp 가 멈춘다.
3. **워커 점유 시간이 길어졌다**(최대 60초 → 90초). 성공률과 맞바꾼 것이고, NICE 는 보통 55초 안에 답한다.
4. **NICE 게이트웨이는 이미 car-erp 로 컷오버돼 있다** — `nginx: location = /provide/api/nice-lookup` → `ProvideNiceLookupController`. **3사 ERP 의 `NICE_PROVIDE_URL` 이 전부 `https://heymancar.com/provide/api/nice-lookup/`**, 즉 `54.116.7.83` 한 박스로 모인다. Django(gunicorn)는 `/provide/` 나머지 prefix 만 받고 **2026-06-27 이후 요청 0건**.

## 4. 아직 안 한 것 (ERP 판단 필요)

- **NICE 조회에 동시 전역 상한이 없다.** NICE 조회 1건은 55~90초 동안 워커를 잡는다. 지금은 ERP UI 조회뿐이라 **`54.116.7.83` 워커 2칸**(ERP UI → `/provide`)이지만, 증설 전 5칸이던 때는 **3명만 동시에 눌러도 박스가 멈추는** 상태였다.
  - 각 ERP 인스턴스가 자기 쪽에 상한을 걸어도 **인스턴스별 DB·캐시가 분리**돼 ssancarerp·heymanerp·karabaerp 를 합산 통제할 수 없다.
  - **모든 NICE 조회가 반드시 지나는 유일한 지점 = `ProvideNiceLookupController`**(3사 `NICE_PROVIDE_URL` 이 전부 여기). 진짜 전역 상한은 여기에만 걸 수 있다 — 컷오버로 PHP 가 됐으니 이제 car-erp 코드로 가능하다. **판단은 ERP 몫**이고 board 와는 무관하다.
- **Django 철거** — 트래픽 0 이라 지금이 안전한 시점. 철거하면 nginx `/provide/` prefix 블록도 같이 정리된다.

## 5. 후속

board 에 「원부조회」 버튼을 얹는 건 **별도 인계문서** `handoff-car-erp-wonbu-lookup.md` 로 간다 — 그건 **Carmodoo**(압류·저당) 이식 요청이고 이 문서의 NICE 타임아웃 이슈와는 별개다. 이 문서는 **이미 적용된 인프라 사실**만 전달한다.
