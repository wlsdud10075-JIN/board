// 씬 점검(화면 회귀) — 업무 가이드 씬을 사진 대신 「가이드가 인용한 화면 문구가 떴나 · DB 값이 남았나」로 확인한다(2026-11-16 Jin).
// car-erp 씬 점검(car-erp dev 24f788d2, docs/design/scene-walkthrough.md §8)과 같은 생각 — 파일은 board 것(캡처 도구 계획 형식 재사용).
//
//   cd scripts/guide-shots && node check/run.mjs            전 씬
//   node check/run.mjs manage-edit audit-find               골라서(순서는 SCENES 순)
//
// 순서: 8003 응답 → preflight.php(로컬·board DB·알림톡 꺼짐) → reset.php(가짜 데이터 되돌리기) → 씬마다 새 브라우저 창(로그인 1번)
//       → 결과 표 + out/check/result.json + 마지막 줄 `SUMMARY 통과 N / 실패 M`. 실패해도 다음 씬은 계속.
// 🚫 배포 게이트 아님 · 서버를 띄우지 않는다(Jin 이 띄운다) · 운영 주소 금지 · 결과에 화면 내용을 통째로 찍지 않는다(로컬 DB 에 실명 행이 섞여 있다).
//
// 씬 파일 check/scenes/<slug>.json = {"slug","title","login":{"email"},"viewport","steps":[...]}
//   조작 단계 = 캡처 도구(shot.mjs)와 같은 이름: goto · click · clickText · set · select · autoConfirm · wait · scrollText
//   확인 단계 = {"check": "이름", "text": "화면 문구"} / {"check": "이름", "noText": "..."} /
//              {"check": "이름", "db": "select … where x = ?", "args": ["…"], "expect": {"칸": 값}}  (값 null = 비어 있어야 함, "*" = 비어 있지 않아야 함)
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import puppeteer from 'puppeteer-core';

const BASE = 'http://localhost:8003';
const SCENES = ['when-blocked', 'manage-edit', 'users-manage', 'audit-find', 'first-steps'];   // 순서 의미 있음: audit-find 는 manage-edit 이 남긴 기록을 본다

const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const root = path.resolve(here, '../../..');
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

const wanted = process.argv.slice(2);
const list = SCENES.filter((s) => wanted.length === 0 || wanted.includes(s));
const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--hide-scrollbars'] });
const results = [];

for (const slug of list) {
    const scene = JSON.parse(fs.readFileSync(path.join(here, 'scenes', slug + '.json'), 'utf8'));
    const started = Date.now();
    const steps = [];
    const ctx = await browser.createBrowserContext();   // 씬마다 새 창 — 한 창에서 로그인을 바꾸면 두 번째부터 실패할 때가 있었다
    const page = await ctx.newPage();
    page.setDefaultNavigationTimeout(90000);
    await page.setViewport(VIEW[scene.viewport || 'pc']);
    page.on('dialog', async (d) => { await d.accept(); });
    const settle = async () => { try { await page.waitForNetworkIdle({ idleTime: 400, timeout: 8000 }); } catch {} await sleep(250); };
    const bodyHas = (t) => page.evaluate((t) => document.body.innerText.replace(/\s+/g, ' ').includes(t.replace(/\s+/g, ' ')), t);
    let n = 0;
    try {
        await page.goto(BASE + '/login', { waitUntil: 'networkidle2' });
        await page.type('input[type=email]', scene.login.email);
        await page.type('input[type=password]', scene.login.password || 'password');
        await page.keyboard.press('Enter');
        // 로그인 폼은 Livewire 라 주소가 늦게 바뀐다 — 주소가 /login 을 벗어날 때까지 기다린다
        try { await page.waitForFunction(() => !location.pathname.startsWith('/login'), { timeout: 15000 }); }
        catch { throw new Error('로그인 실패: ' + scene.login.email); }
        await settle();
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
                    const row = JSON.parse(php('db.php', [s.db, ...(s.args || [])]));
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
            if (s.goto) { await page.goto(BASE + s.goto, { waitUntil: 'networkidle2' }); await settle(); }
            else if (s.viewport) { await page.setViewport(VIEW[s.viewport]); await settle(); }
            else if (s.autoConfirm) { await page.evaluate(() => { window.confirm = () => true; }); }
            else if (s.click) { await page.waitForSelector(sel(s.click), { visible: true, timeout: 8000 }); await page.click(sel(s.click)); await settle(); }
            else if (s.clickText) {
                const ok = await page.evaluate((text, q) => {
                    const el = [...document.querySelectorAll(q)].find((e) => e.offsetParent !== null && e.textContent.replace(/\s+/g, ' ').includes(text));
                    if (el) { el.scrollIntoView({ block: 'center' }); el.click(); }
                    return !!el;
                }, s.clickText, s.in || 'button, a, [role=button], summary');
                if (!ok) throw new Error(`누를 것 못 찾음: 「${s.clickText}」`);
                await settle();
            }
            else if (s.set) {
                await page.waitForSelector(sel(s.set), { timeout: 8000 });
                await page.evaluate((q, v) => { const el = document.querySelector(q); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }, sel(s.set), String(s.value));
                await settle();
            }
            else if (s.select) {
                await page.waitForSelector(sel(s.select), { visible: true, timeout: 8000 });
                const ok = await page.evaluate((q, text) => {
                    const el = document.querySelector(q); const o = [...el.options].find((x) => x.textContent.includes(text));
                    if (o) { el.value = o.value; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
                    return !!o;
                }, sel(s.select), s.text);
                if (!ok) throw new Error(`고를 항목 못 찾음: 「${s.text}」`);
                await settle();
            }
            else if (s.wait) { await sleep(s.wait); }
            else throw new Error('모르는 단계: ' + JSON.stringify(s));
        }
    } catch (e) {
        steps.push({ step: n, name: '진행', ok: false, detail: e.message.split('\n')[0] });
        try { await page.screenshot({ path: path.join(outDir, slug + '-error.png') }); } catch {}
    }
    await ctx.close();
    const failed = steps.filter((x) => !x.ok);
    results.push({ slug, title: scene.title, ok: failed.length === 0, checks: steps.length, failed, seconds: Math.round((Date.now() - started) / 1000) });
}
await browser.close();

console.log('');
for (const r of results) {
    console.log(`${r.ok ? '✓' : '✗'} ${r.title} (${r.slug}) — 확인 ${r.checks}개 · ${r.seconds}초`);
    for (const f of r.failed) console.log(`FAIL ${r.slug} · ${f.step}단계 「${f.name}」 — ${f.detail}`);
}
fs.writeFileSync(path.join(outDir, 'result.json'), JSON.stringify({ at: new Date().toISOString(), results }, null, 2));
const pass = results.filter((r) => r.ok).length;
console.log(`SUMMARY 통과 ${pass} / 실패 ${results.length - pass}`);
process.exitCode = pass === results.length ? 0 : 1;
