/*
 * board 서비스워커 — 웹푸시 수신 전용 (2026-10-12).
 *
 * ⚠️ fetch 핸들러를 두지 않는다(오프라인 캐시 없음). Livewire 요청을 가로채면 화면이 조용히 옛 응답을 받는다.
 *    이 파일의 역할은 ① push 이벤트 → 알림 표시(진동 포함) ② 알림 클릭 → 포털 열기, 둘뿐이다.
 * 등록·구독은 레이아웃(sidebar.blade.php)의 window.__boardPush 가 한다. 공개 경로 /sw.js, scope '/'.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch (_) { d = { body: e.data ? e.data.text() : '' }; }
    const title = d.title || 'board';
    const opts = {
        body: d.body || '',
        icon: d.icon || undefined,
        badge: d.icon || undefined,
        tag: d.tag || undefined,          // 같은 요청의 재알림은 한 장으로 겹친다
        renotify: !!d.tag,
        vibrate: d.vibrate || [200, 100, 200, 100, 400],   // 안드로이드 — 아이폰은 OS 기본 진동
        requireInteraction: !!d.sticky,   // 거절은 사람이 치울 때까지 남긴다
        data: { url: d.url || '/' },
    };
    e.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) {
                if ('focus' in c) {
                    if ('navigate' in c) { c.navigate(url); }
                    return c.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});
