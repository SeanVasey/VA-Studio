// Genuine outage instead of Playwright offline emulation: a forwarding origin that can be unplugged.
const http = require('http'), net = require('net');
const pw = require(process.env.PWCORE);
const sw = `self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => { const r = event.request;
  if (r.mode !== 'navigate') return;
  event.respondWith((async () => { try { return await fetch(r); } catch { return (await caches.match('/offline.html')) || Response.error(); } })()); });`;
const app = http.createServer((q, s) => {
  if (q.url === '/sw.js') { s.writeHead(200, {'content-type':'application/javascript'}); return s.end(sw); }
  if (q.url === '/offline.html') { s.writeHead(200, {'content-type':'text/html'}); return s.end('<h1>We couldn’t reach the store</h1><button onclick="location.reload()">Try again</button>'); }
  s.writeHead(200, {'content-type':'text/html','cache-control':'no-store'});
  s.end(`<h1>store</h1><script>navigator.serviceWorker.register('/sw.js');caches.open('c').then(c=>c.add('/offline.html'))</script>`);
}).listen(0, '127.0.0.1', async () => {
  const sockets = new Set(); let proxy;
  const plug = port => new Promise(r => { proxy = net.createServer(c => { const u = net.connect(app.address().port, '127.0.0.1'); sockets.add(c); sockets.add(u); c.pipe(u).pipe(c); const d = () => { c.destroy(); u.destroy(); }; c.on('error', d); u.on('error', d); c.on('close', d); u.on('close', d); }).listen(port, '127.0.0.1', () => r(proxy.address().port)); });
  const unplug = () => new Promise(r => { proxy.close(() => r()); for (const s of sockets) s.destroy(); sockets.clear(); });
  const port = await plug(0);
  const browser = await pw.webkit.launch();
  const ctx = await browser.newContext({ ...pw.devices['iPhone 13'], serviceWorkers: 'allow' });
  const p = await ctx.newPage(); const out = {};
  try {
    await p.goto(`http://127.0.0.1:${port}/?q=x`); await p.waitForFunction(() => navigator.serviceWorker.controller !== null); await p.waitForTimeout(300);
    await unplug();
    await p.reload(); out.offline = { url: p.url(), h1: await p.textContent('h1') };
    out.apiFetch = await p.evaluate(async () => { try { await fetch('/api'); return 'unexpected'; } catch { return 'network failure'; } });
    await Promise.all([p.waitForNavigation(), p.click('button')]); out.retryOffline = await p.textContent('h1');
    await plug(port);
    await Promise.all([p.waitForNavigation(), p.click('button')]); out.recovered = { url: p.url(), h1: await p.textContent('h1') };
  } catch (e) { out.error = e.message.split('\n')[0]; }
  console.log(JSON.stringify(out));
  await browser.close(); app.close(); proxy.close();
});
