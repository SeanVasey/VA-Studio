// Standalone: does a SW-controlled WebKit page route an <audio> request through page.route?
const http = require('http');
const pw = require(process.env.PWCORE);
const sw = `self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', event => {
  const r = event.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== self.location.origin || r.headers.has('range')) return;
  if (r.mode !== 'navigate') return;   // same early return as public/storefront-worker.js:71
  if (u.searchParams.has('sw')) { event.respondWith(new Response('<h1>from-sw</h1>', { headers: { 'content-type': 'text/html' } })); return; }
  event.respondWith((async () => { try { return await fetch(r); } catch { return (await caches.match('/offline.html')) || Response.error(); } })());
});`;
const offline = '<!doctype html><title>offline</title><h1>We couldn’t reach the store</h1>';
const page = `<!doctype html><title>t</title><button id=b>Play</button><script>
window.__log=[]; navigator.serviceWorker.register('/sw.js',{scope:'/'});
document.getElementById('b').onclick=()=>{const a=window.__a=new Audio();a.preload='metadata';a.src='/media/x';
a.addEventListener('error',()=>__log.push('error event code '+a.error.code));
a.play().then(()=>__log.push('play resolved'),e=>__log.push('play rejected '+e.name));};
caches.open('c').then(c=>c.add('/offline.html'));
</script>`;
const hits = [];
const server = http.createServer((q, s) => {
  hits.push(q.url);
  if (q.url === '/sw.js') { s.writeHead(200, {'content-type':'application/javascript'}); return s.end(sw); }
  if (q.url === '/offline.html') { s.writeHead(200, {'content-type':'text/html'}); return s.end(offline); }
  if (q.url.startsWith('/media/')) { s.writeHead(404, {'content-type':'text/html'}); return s.end('not found'); }
  s.writeHead(200, {'content-type':'text/html', 'cache-control':'no-store'}); s.end(page);
}).listen(0, '127.0.0.1');
function wav() { const sr=22050,n=sr*10,b=Buffer.alloc(44+n*2); b.write('RIFF',0);b.writeUInt32LE(b.length-8,4);b.write('WAVEfmt ',8);
  b.writeUInt32LE(16,16);b.writeUInt16LE(1,20);b.writeUInt16LE(1,22);b.writeUInt32LE(sr,24);b.writeUInt32LE(sr*2,28);b.writeUInt16LE(2,32);b.writeUInt16LE(16,34);b.write('data',36);b.writeUInt32LE(n*2,40);
  for(let i=0;i<n;i++) b.writeInt16LE(Math.round(1500*Math.sin(2*Math.PI*220*i/sr)),44+i*2); return b; }
const WAV = wav();
server.on('listening', async () => {
  try { const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await pw.webkit.launch();
  for (const mode of process.argv.slice(2)) {
    hits.length = 0;
    const [serviceWorkers, scenario] = mode.split(':');
    const ctx = await browser.newContext({ ...pw.devices['iPhone 13'], baseURL: base, serviceWorkers });
    const p = await ctx.newPage(); const routed = [];
    p.on('console', m => routed.push('console:' + m.text()));
    if (scenario === 'audio') {
      await p.route('**/*', r => { const u = new URL(r.request().url()); if (u.pathname === '/media/x') { routed.push('route:' + u.pathname); return r.fulfill({ status: 200, headers: {'Content-Type':'audio/wav','Accept-Ranges':'bytes'}, body: WAV }); } return r.continue(); });
      await p.goto('/');
      if (serviceWorkers === 'allow') await p.waitForFunction(() => navigator.serviceWorker.controller !== null);
      await p.click('#b'); await p.waitForTimeout(2500);
      const r = await p.evaluate(() => ({ t: window.__a.currentTime, log: window.__log, ctrl: !!navigator.serviceWorker?.controller }));
      console.log(JSON.stringify({ mode, ...r, routed, serverHits: hits.filter(h => h.startsWith('/media')) }));
    } else {
      await p.goto('/'); await p.waitForFunction(() => navigator.serviceWorker.controller !== null); await p.waitForTimeout(300);
      await ctx.setOffline(true);
      let res;
      const how = scenario.split('-')[1] || 'reload';
      try {
        if (how === 'reload') await p.reload();
        else if (how === 'goto') await p.goto('/?q=x');
        else if (how === 'swonline') { await ctx.setOffline(false); await p.goto('/?sw=1'); }
        else if (how === 'swoffline') await p.goto('/?sw=1');
        else if (how === 'jsreload') await Promise.all([p.waitForEvent('load', { timeout: 10000 }), p.evaluate(() => { setTimeout(() => location.reload(), 0); })]);
        else if (how === 'jsnav') await Promise.all([p.waitForEvent('load', { timeout: 10000 }), p.evaluate(() => { setTimeout(() => { location.href = '/?q=y'; }, 0); })]);
        res = how + ' ok: url=' + p.url() + ' h1=' + await p.evaluate(() => document.querySelector('h1')?.textContent ?? null);
      } catch (e) { res = how + ' threw: ' + e.message.split('\n')[0] + ' url=' + p.url(); }
      console.log(JSON.stringify({ mode, res, routed }));
    }
    await ctx.close();
  }
  await browser.close(); server.close(); } catch (e) { console.error(e); process.exit(1); }
});
