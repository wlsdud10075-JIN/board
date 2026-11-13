// 업무 가이드(씬 워크스루) 화면 캡처 — 로컬 전용 (2026-11-13).
//
//   cd scripts/guide-shots && npm install
//   node shot.mjs plans/<씬>.json
//
// plan(JSON) = { "slug": "payment-request", "base": "http://localhost:8003", "login": {"email": "...", "password": "password"},
//                "viewport": "pc" | "phone", "steps": [ ... ] }
// step 종류 (위에서 아래로 실행):
//   {"goto": "/listings"}                       주소 이동(base 뒤에 붙는다)
//   {"click": "css 선택자"}                      누르기
//   {"clickText": "매입잔금", "in": "button"}     글자가 들어간 요소 누르기("in" 생략 = button, a, [role=button], summary)
//   {"type": "css 선택자", "text": "5000000"}    입력 (선택자 줄임말: "input@model(address)" = wire:model 이 address 인 input)
//   {"select": "select@model(company_id)", "text": "위카"}   글자가 들어간 option 고르기
//   {"scrollText": "요청 이력"}                그 글자가 보이게 스크롤
//   {"upload": "input@model(salesFiles)", "files": ["fixtures/a.jpg"]}   파일 올리기(숨은 입력도)
//   {"select": "...", "index": 1}             option 을 순서로 고르기
//   {"wait": 800}                               ms 대기 / {"waitFor": "css 선택자"} 요소가 나타날 때까지
//   {"viewport": "phone"}                       도중에 폭 바꾸기
//   {"login": {"email": "..."}}                 도중에 다른 계정으로 다시 로그인
//   {"dialog": "accept" | "dismiss"}            다음에 뜨는 confirm/alert 처리(기본 = accept)
//   {"shot": "01", "of": "css 선택자", "full": true, "clip": {x,y,width,height}}   out/<slug>/01.png — "of" 가 있으면 그 요소만, "full" 이면 페이지 전체
//
// 🚫 운영 주소로 돌리지 말 것 — 가이드 캡처는 로컬의 가짜 계정·가짜 차량으로만 찍는다(실명·전화·실차량번호 금지).
// ⚠️ base 는 .env 의 APP_URL 과 같은 호스트로(localhost ≠ 127.0.0.1 — 다르면 Livewire 버튼이 죽는다).
import fs from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const CHROME = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const VIEW = { pc: { width: 1280, height: 900, deviceScaleFactor: 1 }, tall: { width: 1280, height: 1500, deviceScaleFactor: 1 }, xtall: { width: 1280, height: 3400, deviceScaleFactor: 1 }, phone: { width: 390, height: 844, deviceScaleFactor: 2, isMobile: true, hasTouch: true } };

const planPath = process.argv[2];
if (!planPath) { console.error('사용: node shot.mjs plans/<씬>.json'); process.exit(1); }
const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
const base = (plan.base || 'http://localhost:8003').replace(/\/$/, '');
if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/.test(base)) { console.error('로컬 주소만 허용합니다: ' + base); process.exit(1); }
const outDir = path.join(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), 'out', plan.slug);
fs.mkdirSync(outDir, { recursive: true });

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--hide-scrollbars'] });
const page = await browser.newPage();
await page.setViewport(VIEW[plan.viewport || 'pc']);
let dialogMode = 'accept';
page.on('dialog', async (d) => { console.log('  dialog: ' + d.message().slice(0, 80)); dialogMode === 'dismiss' ? await d.dismiss() : await d.accept(); });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// 선택자 줄임말 — "select@model(company_id)" → wire:model(.live) 가 company_id 인 select. JSON 에 역슬래시를 안 쓰려고 둔다.
const sel = (q) => q.replace(/@model\(([^)]+)\)/g, (_, m) => `:is([wire\\:model="${m}"],[wire\\:model\\.live="${m}"],[wire\\:model\\.blur="${m}"],[wire\\:model\\.live\\.debounce\\.400ms="${m}"],[wire\\:model\\.live\\.debounce\\.300ms="${m}"])`).replace(/@click\(([^)]+)\)/g, (_, m) => `[wire\\:click="${m}"]`)   // "button@click(openPanel)" → wire:click 이 openPanel 인 버튼
    .replace(/@key\(([^)]+)\)/g, (_, m) => `[wire\\:key="${m}"]`);   // "div@key(tr-4)" → wire:key 가 tr-4 인 요소
const settle = async () => { try { await page.waitForNetworkIdle({ idleTime: 400, timeout: 8000 }); } catch {} await sleep(250); };

async function login({ email, password = 'password' }) {
    const client = await page.createCDPSession();
    await client.send('Network.clearBrowserCookies');
    await page.goto(base + '/login', { waitUntil: 'networkidle2' });
    await page.type('input[type=email], input[name=email]', email);
    await page.type('input[type=password]', password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }).catch(() => {}), page.keyboard.press('Enter')]);
    await settle();
    if (page.url().includes('/login')) { throw new Error('로그인 실패: ' + email); }
    console.log('  login ' + email + ' → ' + page.url());
}

try {
    if (plan.login) await login(plan.login);
    for (const s of plan.steps) {
        if (s.goto) { await page.goto(/^https?:/.test(s.goto) ? s.goto : base + s.goto, { waitUntil: 'networkidle2' }); await settle(); }   // 서명 링크처럼 전체 주소도 받는다(로컬만)
        else if (s.login) { await login(s.login); }
        else if (s.viewport) { await page.setViewport(VIEW[s.viewport]); await settle(); }
        else if (s.dialog) { dialogMode = s.dialog; }
        else if (s.click) { await page.waitForSelector(sel(s.click), { visible: true, timeout: 8000 }); await page.click(sel(s.click)); await settle(); }
        else if (s.clickText) {
            const ok = await page.evaluate((text, sel) => {
                const el = [...document.querySelectorAll(sel)].find((e) => e.offsetParent !== null && e.textContent.replace(/\s+/g, ' ').includes(text));
                if (el) { el.scrollIntoView({ block: 'center' }); el.click(); }
                return !!el;
            }, s.clickText, s.in || 'button, a, [role=button], summary');
            if (!ok) throw new Error('못 찾음: ' + s.clickText);
            await settle();
        }
        else if (s.type) { await page.waitForSelector(sel(s.type), { visible: true, timeout: 8000 }); await page.click(sel(s.type), { clickCount: 3 }); await page.type(sel(s.type), String(s.text)); await page.keyboard.press('Tab'); await settle(); }
        else if (s.select) {   // {"select": "select@model(company_id)", "text": "위카"} — 글자가 들어간 option 고르기
            await page.waitForSelector(sel(s.select), { visible: true, timeout: 8000 });
            const ok = await page.evaluate((q, text, index) => {   // "index": 1 = 첫 번째 실제 항목(0 은 「선택」 자리)
                const el = document.querySelector(q); const o = index != null ? el.options[index] : [...el.options].find((x) => x.textContent.includes(text));
                if (o) { el.value = o.value; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
                return !!o;
            }, sel(s.select), s.text ?? '', s.index ?? null);
            if (!ok) throw new Error('option 못 찾음: ' + s.text);
            await settle();
        }
        else if (s.scrollText) {   // {"scrollText": "요청 이력"} — 그 글자가 든 가장 안쪽 요소를 화면 위쪽으로
            await page.evaluate((text) => {
                const els = [...document.querySelectorAll('body *')].filter((e) => e.offsetParent !== null && e.children.length === 0 && e.textContent.includes(text));
                if (els[0]) els[0].scrollIntoView({ block: 'start' });
            }, s.scrollText);
            await sleep(400);
        }
        else if (s.upload) {   // {"upload": "input@model(salesFiles)", "files": ["fixtures/a.jpg"]} — 숨은 file 입력에도 된다(경로는 이 폴더 기준)
            const el = await page.waitForSelector(sel(s.upload), { timeout: 8000 });
            await el.uploadFile(...s.files.map((f) => path.resolve(path.dirname(outDir), '..', f)));
            await settle(); await sleep(s.after ?? 1500);
        }
        else if (s.waitFor) { await page.waitForSelector(sel(s.waitFor), { visible: true, timeout: 15000 }); }
        else if (s.wait) { await sleep(s.wait); }
        else if (s.shot) {
            const file = path.join(outDir, s.shot + '.png');
            if (s.of) { const el = await page.waitForSelector(sel(s.of), { visible: true, timeout: 8000 }); await el.screenshot({ path: file }); }
            else if (s.clip) { await page.screenshot({ path: file, clip: s.clip }); }   // {"clip": {"x":840,"y":0,"width":440,"height":900}} — 화면 한쪽만
            else { await page.screenshot({ path: file, fullPage: !!s.full }); }
            console.log('  shot ' + file);
        }
    }
} catch (e) {
    await page.screenshot({ path: path.join(outDir, '_error.png') });
    console.error('실패: ' + e.message + ' (화면 = out/' + plan.slug + '/_error.png)');
    process.exitCode = 1;
} finally {
    await browser.close();
}
