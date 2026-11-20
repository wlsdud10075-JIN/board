// 씬 점검(화면 회귀) — 업무 가이드 씬을 사진 대신 「가이드가 인용한 화면 문구가 떴나 · DB 값이 남았나」로 확인한다(2026-11-16 Jin).
// car-erp 씬 점검(car-erp dev 24f788d2, docs/design/scene-walkthrough.md §8)과 같은 생각 — 파일은 board 것(캡처 도구 계획 형식 재사용).
//
//   cd scripts/guide-shots && node check/run.mjs            전 씬
//   node check/run.mjs manage-edit audit-find               골라서(순서는 SCENES 순)
//   node check/run.mjs --mark                               끝나면 관제 「우리 루틴」(키 board-scene-check)에 기록 — ERP 토요일 실행기가 이어서 부를 때
//
// 순서: 8003 응답 → preflight.php(로컬·board DB·알림톡 꺼짐) → reset.php(가짜 데이터 되돌리기) → 씬마다 새 브라우저 창
//       → 결과 표 + out/check/result.json + 마지막 줄 `SUMMARY 통과 N / 실패 M`. 실패해도 다음 씬은 계속.
// 🚫 배포 게이트 아님 · 서버를 띄우지 않는다(Jin 이 띄운다) · 운영 주소 금지 · 결과에 화면 내용을 통째로 찍지 않는다(로컬 DB 에 실명 행이 섞여 있다).
//
// 씬 파일 check/scenes/<slug>.json = {"slug","title","login":{"email"},"viewport","needs","allowClicks","steps":[...]}
//   조작 단계 = 캡처 도구(shot.mjs)와 같은 이름: goto · click · clickText · set · select · autoConfirm · wait
//              + {"login":{"email"}} = 씬 중간에 다른 계정으로(새 창)
//   확인 단계 = {"check": "이름", "text": "화면 문구"} / {"check": "이름", "noText": "..."} /
//              {"check": "이름", "db": "select … where x = ?", "args": ["…"], "expect": {"칸": 값}}  (값 null = 비어 있어야 함, "*" = 비어 있지 않아야 함)
//              args 의 "{since}" = 이번 실행 시작 시각(DB 시계) — 「이번에 새로 생긴 기록」만 보려면 created_at >= ? 와 함께.
//   "needs": ["erp"] = 로컬 ERP(127.0.0.1:8001)가 있어야 하는 씬 — 꺼져 있으면 실패가 아니라 「건너뜀」.
//   "allowClicks": ["conclude"] = 아래 DENY 버튼 중 이 씬이 일부러 누르는 것(막히는 걸 확인하려고 등). 그 밖의 DENY 버튼은 누르려는 순간 실패로 멈춘다.
//   {"upload": "input@model(salesFiles)", "files": ["board-check-doc.pdf"]} = 파일 올리기(숨은 입력도) — 경로 = 씬 폴더 → guide-shots/fixtures → guide-shots 순으로 찾는다.
//      ⚠️ /auction 에는 PDF(서류)만 — 사진(jpg)은 로컬에서 회사 GPU PC 계좌 읽기를 부른다(board.payee_extract.enabled, 큐 sync).
//   {"gotoPhp": "claim-link.php", "args": ["부장"]} = 씬 폴더 php 가 찍은 로컬 주소로 이동(서명 링크 — 주소는 결과에 안 남긴다).
//   set 값의 "{today}" = 오늘 날짜(YYYY-MM-DD, DB 시계).
//   "before"/"after" = 씬 폴더 기준 php 파일(씬 동안만 설정을 바꿨다 되돌림, after 는 실패해도 돈다).
// 환경변수(백그라운드 에이전트가 레포를 안 건드리고 시험할 때): SCENES_DIR=다른 씬 폴더 · SCENES=쉼표 목록(순서) · EXTRA_RESET=추가 되돌리기 php 파일
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import puppeteer from 'puppeteer-core';

const BASE = 'http://localhost:8003';
const SCENES = process.env.SCENES ? process.env.SCENES.split(',')
    : ['when-blocked', 'manage-edit', 'users-manage', 'audit-find', 'first-steps',          // 1단계 — board 안에서 끝남
        'portal-read', 'settlement-read', 'portal-share',                                      // 2a — 포털(ERP 읽기만)
        'listing-register', 'listing-delete',                                                  // 2a — 매입예정 등록·삭제(검차 대상 차 — 로컬 ssancar.com 큐에 upsert·withdraw, Jin 「로컬이니 괜찮다」 11-16)
        'self-inspection', 'listing-delete-rejected',                                          // 2a — 매입예정(검차 큐에 안 들어가는 차)
        'work-journal', 'work-journal-no-ceo',                                                 // 2a — 업무일지(5계정 결재·직급 비우기)
        // 2b — 로컬 ERP 에 쓰는 씬(needs erp-reset → ERP 시더가 막 돈 --after-erp 실행에서만). 전용 영업 board-check · 30가30xx · 점검 바이어 1·2·3(매입 락 0)
        'purchase-confirm', 'stock-purchase', 'erp-resend', 'payment-request', 'sale-payment-check', 'shipping-plan',
        'claim-erp-send', 'transport-balance'];
// 순서 의미 있음: audit-find 는 manage-edit 이 남긴 기록을 본다. work-journal-no-ceo 는 오늘 결재 중 기안이 없어야 한다(work-journal 이 최종 승인까지 간 뒤).
// listing-register → listing-delete 는 같은 차(11가1101) — 등록이 큐에 upsert, 삭제가 withdraw 로 정리한다(로컬 8002).
// ERP·바깥 서비스로 쓰거나 바깥을 부르는 버튼(wire:click 메서드 이름) — 씬이 allowClicks 로 열지 않으면 누르지 않는다.
//   로컬 ERP 데이터는 board 가 되돌릴 수 없고(ERP 가 알림톡도 보낼 수 있다), 엔카·ssancar·GPU PC 는 바깥 서비스다.
const DENY = ['conclude', 'requestLockApproval', 'cancelLockRequest', 'sendPurchaseRequest', 'sendSaleConfirm', 'syncBundles', 'cancelBundle', 'requestBl', 'cancelBl', 'requestChange', 'requestSignature',
    'sendToErp', 'resyncToCarErp', 'resendToErp', 'resendSplit', 'sendRemitter', 'addAttachments', 'extractPassport', 'extractFromImages', 'parseLink',
    'requestReinspection', 'requestUrgent', 'requestBalance', 'resend'];

const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const root = path.resolve(here, '../../..');
const scenesDir = process.env.SCENES_DIR || path.join(here, 'scenes');
const outDir = path.join(here, '..', 'out', 'check');
fs.mkdirSync(outDir, { recursive: true });
const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const VIEW = { pc: { width: 1280, height: 900 }, tall: { width: 1280, height: 1500 }, phone: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true } };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sel = (q) => q.replace(/@model\(([^)]+)\)/g, (_, m) => `:is([wire\\:model="${m}"],[wire\\:model\\.live="${m}"],[wire\\:model\\.blur="${m}"],[wire\\:model\\.live\\.debounce\\.400ms="${m}"],[wire\\:model\\.live\\.debounce\\.300ms="${m}"],[wire\\:model\\.live\\.debounce\\.600ms="${m}"])`);
const php = (file, args = []) => execFileSync('php', [path.join(here, file), ...args], { cwd: root, encoding: 'utf8' }).trim();

function die(msg) { console.error('멈춤: ' + msg); console.log('SUMMARY 통과 0 / 실패 0 (시작 못 함)'); process.exit(2); }

// ── 시작 전 확인 ──
try { const r = await fetch(BASE + '/login'); if (r.status !== 200) die(`8003 이 ${r.status} — 로컬 서버를 확인하세요`); }
catch { die('8003 응답 없음 — 로컬 서버(php artisan serve --port=8003)를 Jin 이 띄워야 합니다'); }
try { php('preflight.php'); } catch (e) { die('사전 확인 실패 — ' + (e.stdout || e.message).toString().trim()); }
try { console.log('되돌리기: ' + JSON.parse(php('reset.php')).done.join(' · ')); } catch (e) { die('되돌리기 실패 — ' + (e.stdout || e.message).toString().trim()); }
// 씬 묶음별 되돌리기 — check/resets/*.php(이름순). 예시가 없으면 {ok:false} 로 멈춘다.
for (const f of fs.readdirSync(path.join(here, 'resets')).filter((x) => x.endsWith('.php')).sort()) {
    try {
        const r = JSON.parse(php(path.join('resets', f)));
        if (r.ok === false) die(`되돌리기(${f}) 멈춤 — ${r.problem}`);
        console.log(`되돌리기(${f}): ` + (r.done || []).join(' · '));
    } catch (e) { die(`되돌리기(${f}) 실패 — ` + (e.stdout || e.message).toString().trim()); }
}
if (process.env.EXTRA_RESET) {
    try { console.log('추가 되돌리기: ' + execFileSync('php', [process.env.EXTRA_RESET], { cwd: root, encoding: 'utf8' }).trim()); }
    catch (e) { die('추가 되돌리기 실패 — ' + (e.stdout || e.message).toString().trim()); }
}
const since = JSON.parse(php('db.php', ['select now() as t'])).t;
const todayStr = String(since).slice(0, 10);   // DB 시계(KST) — 「이번 실행에서 생긴 기록」 기준
const erpUp = await fetch('http://127.0.0.1:8001/login').then((r) => r.status === 200).catch(() => false);

const mark = process.argv.includes('--mark');
// --after-erp = 바로 앞에서 ERP 시더(GuideSceneFixtureSeeder — 30가30xx·board-check 행 되돌리기)가 돌았다(ERP 토요일 실행기가 붙인다).
//   ERP 에 쓰는 2b 씬("needs": ["erp-reset"])은 이 표시가 있을 때만 돈다 — 없으면 지난 실행이 남긴 ERP 행 때문에 결과가 달라진다.
const afterErp = process.argv.includes('--after-erp');   // 끝나면 관제 「우리 루틴」에 기록(ERP 토요일 실행기가 이어서 부를 때)
const wanted = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const list = SCENES.filter((s) => wanted.length === 0 || wanted.includes(s));
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--hide-scrollbars'] });
const results = [];

for (const slug of list) {
    const scene = JSON.parse(fs.readFileSync(path.join(scenesDir, slug + '.json'), 'utf8'));
    const started = Date.now();
    if ((scene.needs || []).includes('erp') && !erpUp) {
        results.push({ slug, title: scene.title, ok: true, skipped: '로컬 ERP(8001) 꺼짐', checks: 0, failed: [], seconds: 0 });
        continue;
    }
    if ((scene.needs || []).includes('erp-reset') && !afterErp) {
        results.push({ slug, title: scene.title, ok: true, skipped: 'ERP 되돌리기 안 됨(--after-erp 없음)', checks: 0, failed: [], seconds: 0 });
        continue;
    }
    const allow = new Set(scene.allowClicks || []);
    const steps = [];
    let ctx = null;
    let page = null;
    const settle = async () => { try { await page.waitForNetworkIdle({ idleTime: 400, timeout: 8000 }); } catch {} await sleep(250); };
    const bodyHas = (t) => page.evaluate((t) => document.body.innerText.replace(/\s+/g, ' ').includes(t.replace(/\s+/g, ' ')), t);
    // 씬마다(그리고 씬 중간 계정마다) 새 창 — 한 창에서 로그인을 바꾸면 두 번째부터 실패할 때가 있었다
    const loginAs = async (who) => {
        if (ctx) await ctx.close();
        ctx = await browser.createBrowserContext();
        page = await ctx.newPage();
        page.setDefaultNavigationTimeout(90000);
        await page.setViewport(VIEW[scene.viewport || 'pc']);
        page.on('dialog', async (d) => { await d.accept(); });
        await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
        await page.type('input[type=email]', who.email);
        await page.type('input[type=password]', who.password || 'password');
        await page.keyboard.press('Enter');
        // 로그인 폼은 Livewire 라 주소가 늦게 바뀐다 — 주소가 /login 을 벗어날 때까지 기다린다
        try { await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 }); }
        catch { throw new Error('로그인 실패: ' + who.email); }
        await settle();
    };
    // 누르려는 요소(또는 조상)의 wire:click 메서드가 DENY 인데 씬이 허용 안 했으면 멈춘다
    const guard = async (handle) => {
        // 요소와 조상들의 wire:click · @click · x-on:click 에서 부르는 메서드 이름을 전부 모은다(「$set(…); $wire.conclude(…)」·「$wire.sendPurchaseRequest(…)」도 잡게)
        const names = await handle.evaluate((el) => {
            const out = [];
            for (let e = el; e && e.getAttribute; e = e.parentElement) {
                for (const a of ['wire:click', '@click', 'x-on:click']) {
                    const v = e.getAttribute(a);
                    if (!v) continue;
                    if (a === 'wire:click') out.push(v.split('(')[0].trim());
                    for (const m of v.matchAll(/\$wire\.([A-Za-z_]+)\s*\(/g)) out.push(m[1]);
                    for (const m of v.matchAll(/(?:^|[;\s])([A-Za-z_]+)\s*\(/g)) out.push(m[1]);
                }
            }
            return out;
        });
        const bad = names.find((m) => DENY.includes(m) && !allow.has(m));
        if (bad) throw new Error(`막은 버튼을 누르려 함: ${bad} (씬 allowClicks 에 없음)`);
    };
    let n = 0;
    // "before"/"after" = 씬 폴더 기준 php 파일 — 씬 동안만 설정을 바꿨다 되돌릴 때(예: 결재 직급 비우기). after 는 실패해도 반드시 돈다.
    const hook = (f) => execFileSync('php', [path.resolve(scenesDir, f)], { cwd: root, encoding: 'utf8' }).trim();
    try {
        if (scene.before) hook(scene.before);
        await loginAs(scene.login);
        for (const s of scene.steps) {
            n++;
            if (s.check) {
                let ok = false; let detail = '';
                if (s.text !== undefined) {
                    for (let t = 0; t < 20 && !ok; t++) { ok = await bodyHas(s.text); if (!ok) await sleep(500); }
                    detail = ok ? '' : `화면에 「${s.text}」 없음`;
                } else if (s.noText !== undefined) {
                    await sleep(500); ok = !(await bodyHas(s.noText)); detail = ok ? '' : `화면에 「${s.noText}」가 있으면 안 됨`;
                } else if (s.db) {
                    const row = JSON.parse(php('db.php', [s.db, ...(s.args || []).map((a) => (a === '{since}' ? since : a))]));
                    const bad = Object.entries(s.expect).filter(([k, v]) => {
                        const got = row ? row[k] : undefined;
                        if (v === null) return got !== null && got !== undefined;
                        if (v === '*') return got === null || got === undefined || got === '';
                        return String(got) !== String(v);
                    });
                    ok = bad.length === 0;
                    detail = ok ? '' : bad.map(([k, v]) => `${k}: 기대 ${v === null ? '비어 있음' : v} / 실제 ${row ? row[k] : '(행 없음)'}`).join(', ');
                }
                steps.push({ step: n, name: s.check, ok, detail });
                continue;
            }
            if (s.login) { await loginAs(s.login); }
            else if (s.goto) { await page.goto(BASE + s.goto, { waitUntil: 'networkidle2' }); await settle(); }
            else if (s.viewport) { await page.setViewport(VIEW[s.viewport]); await settle(); }
            else if (s.autoConfirm) { await page.evaluate(() => { window.confirm = () => true; }); }
            else if (s.click) {
                const h = await page.waitForSelector(sel(s.click), { visible: true, timeout: 8000 });
                await guard(h);
                await h.click();
                await settle();
            }
            else if (s.clickText) {
                const h = await page.evaluateHandle((text, q) => [...document.querySelectorAll(q)].find((e) => e.offsetParent !== null && e.textContent.replace(/\s+/g, ' ').includes(text)) || null,
                    s.clickText, s.in || 'button, a, [role=button], summary');
                if (!(await h.evaluate((el) => !!el))) throw new Error(`누를 것 못 찾음: 「${s.clickText}」`);
                await guard(h);
                await h.evaluate((el) => { el.scrollIntoView({ block: 'center' }); el.click(); });
                await settle();
            }
            else if (s.set) {
                await page.waitForSelector(sel(s.set), { timeout: 8000 });
                await page.evaluate((q, v) => { const el = document.querySelector(q); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, sel(s.set), String(s.value).replaceAll('{today}', todayStr));
                await settle();
            }
            else if (s.select) {
                // 같은 칸이 접힌 묶음 안에도 있으면(선적 계획의 바이어별 포워딩사 등) querySelector 첫 번째가 숨은 칸이다 → 보이는 첫 칸을 쓴다
                await page.waitForFunction((q) => [...document.querySelectorAll(q)].some((e) => e.offsetParent !== null), { timeout: 8000 }, sel(s.select));
                const ok = await page.evaluate((q, text, without, exact) => {
                    // "without": "🔒" = 그 글자가 없는 첫 실제 항목(이름을 박지 않고 「잠기지 않은 바이어」처럼 고를 때 — 예시 바이어 상태는 ERP 데이터라 바뀐다)
                    const el = [...document.querySelectorAll(q)].find((e) => e.offsetParent !== null);
                    // "exact": true = 항목 이름(「 (」·「 · 」 앞)이 text 와 똑같은 것 — 「BOARD CHECK TRADING」 이 「… 2」·「… 3」 까지 잡지 않게
                    const name = (x) => x.textContent.trim().replace(/^🔒\s*/, '').split(/ \(| · /)[0].trim();
                    const o = [...el.options].find((x) => (without !== undefined ? x.value !== '' && !x.textContent.includes(without)
                        : exact ? name(x) === text : x.textContent.includes(text)));
                    if (o) { el.value = o.value; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
                    return !!o;
                }, sel(s.select), s.text, s.without, !!s.exact);
                if (!ok) throw new Error(`고를 항목 못 찾음: 「${s.text ?? '(' + s.without + ' 없는 항목)'}」`);
                await settle();
            }
            else if (s.upload) {
                const el = await page.waitForSelector(sel(s.upload), { timeout: 8000 });
                const files = s.files.map((f) => [path.resolve(scenesDir, f), path.resolve(here, '..', 'fixtures', f), path.resolve(here, '..', f)].find((x) => fs.existsSync(x)));
                if (files.some((f) => !f)) throw new Error('올릴 파일 없음: ' + s.files.join(', '));
                await el.uploadFile(...files);
                await settle(); await sleep(s.after ?? 1500);
            }
            else if (s.gotoPhp) {
                const url = execFileSync('php', [path.resolve(scenesDir, s.gotoPhp), ...(s.args || [])], { cwd: root, encoding: 'utf8' }).trim().split('\n').pop();
                if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(url)) throw new Error('gotoPhp 가 로컬 주소를 안 줬다');
                await page.goto(url, { waitUntil: 'networkidle2' }); await settle();
            }
            else if (s.wait) { await sleep(s.wait); }
            else throw new Error('모르는 단계: ' + JSON.stringify(s));
        }
    } catch (e) {
        steps.push({ step: n, name: '진행', ok: false, detail: e.message.split('\n')[0] });
        try { await page.screenshot({ path: path.join(outDir, slug + '-error.png') }); } catch {}
    } finally {
        if (scene.after) {
            try { hook(scene.after); } catch (e) { steps.push({ step: n, name: '뒷정리(after)', ok: false, detail: (e.stdout || e.message).toString().split('\n')[0] }); }
        }
    }
    if (ctx) await ctx.close();
    const failed = steps.filter((x) => !x.ok);
    results.push({ slug, title: scene.title, ok: failed.length === 0, checks: steps.length, failed, seconds: Math.round((Date.now() - started) / 1000) });
}
await browser.close();

console.log('');
for (const r of results) {
    if (r.skipped) { console.log(`– ${r.title} (${r.slug}) — 건너뜀: ${r.skipped}`); continue; }
    console.log(`${r.ok ? '✓' : '✗'} ${r.title} (${r.slug}) — 확인 ${r.checks}개 · ${r.seconds}초`);
    for (const f of r.failed) console.log(`FAIL ${r.slug} · ${f.step}단계 「${f.name}」 — ${f.detail}`);
}
fs.writeFileSync(path.join(outDir, 'result.json'), JSON.stringify({ at: new Date().toISOString(), results }, null, 2));
const skip = results.filter((r) => r.skipped).length;
const pass = results.filter((r) => r.ok && !r.skipped).length;
const fail = results.length - pass - skip;
console.log(`SUMMARY 통과 ${pass} / 실패 ${fail}` + (skip ? ` / 건너뜀 ${skip}` : ''));
process.exitCode = fail === 0 ? 0 : 1;
if (mark) {
    const note = `통과 ${pass} / 실패 ${fail}` + (skip ? ` / 건너뜀 ${skip}` : '') + (fail ? ' — ' + results.filter((r) => !r.ok).map((r) => r.slug).join(', ') : '');
    try { console.log('관제 기록: ' + php('ops-mark.php', [fail === 0 ? 'ok' : 'failed', note])); }
    catch (e) { console.log('관제 기록 실패: ' + (e.stdout || e.message).toString().trim()); }
}
