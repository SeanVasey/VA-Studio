// Standalone: does response.finished() resolve for a route.continue()d POST whose body the page reads
// with a ReadableStream reader and then cancels (as privateInquiryJson does)? Pinned Playwright 1.63 + Chromium 1243.
// This is the Run D script (30 x continue, 30 x fetch+fulfill); runs A-C used earlier edits with other variants.
import http from 'node:http';
import { chromium } from '/home/user/VA-Studio/node_modules/playwright-core/index.mjs';

const server = http.createServer((req, res) => {
  if (req.url === '/') { res.writeHead(200, { 'content-type': 'text/html' }); res.end('<!doctype html><button>go</button>'); return; }
  let body = ''; req.on('data', c => body += c); req.on('end', () => {
    res.writeHead(req.url.includes('first') ? 201 : 200, { 'content-type': 'application/json', 'cache-control': 'no-store' });
    res.end(JSON.stringify({ state: 'saved', receipt: '00000000-0000-4000-8000-000000000000' }));
  });
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch();
const results = {};
const tally = {};
for (const [variant, routed] of Array.from({length: 30}, () => [['reader-cancel',true],['reader-cancel-fulfill',true]]).flat()) {
  const page = await browser.newPage();
  await page.goto(origin + '/');
  let n = routed ? 0 : 1;
  if (routed) await page.route('**/inquiry', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    if (++n === 1) { await route.fetch(); await route.abort('failed'); return; }
    if (variant.endsWith('-fulfill')) { const result = await route.fetch(); await route.fulfill({ response: result }); return; }
    await route.continue();
  });
  const send = (v) => page.evaluate(async (v) => {
    const abort = new AbortController();
    try {
      const response = await fetch('/inquiry', { method: 'POST', cache: 'no-store', signal: abort.signal, headers: { 'Content-Type': 'application/json' }, body: '{}' });
      if (v === 'response-json') return await response.json();
      const reader = response.body.getReader(); let done = false; const cancel = () => { void reader.cancel().catch(() => {}); };
      abort.signal.addEventListener('abort', cancel, { once: true });
      try { const chunks = []; while (true) { const r = await reader.read(); if (r.done) { done = true; break; } chunks.push(r.value); }
        return JSON.parse(new TextDecoder().decode(chunks.length ? chunks[0] : new Uint8Array())); }
      finally { abort.signal.removeEventListener('abort', cancel);
        if (v.startsWith('reader-cancel') && !v.includes('if-not-done') || (v === 'reader-cancel-if-not-done' && !done)) cancel(); reader.releaseLock(); }
    } catch (e) { return 'error:' + e.name; }
  }, v);
  if (routed) results[variant + ' first'] = await send(variant);
  const replay = page.waitForResponse(r => new URL(r.url()).pathname === '/inquiry' && r.request().method() === 'POST');
  const second = send(variant);
  const response = await replay;
  results[variant + (routed?'':' unrouted') + ' second status'] = response.status();
  results[variant + (routed?'':' unrouted') + ' second finished'] = await Promise.race([response.finished().then(v => 'resolved:' + v), new Promise(r => setTimeout(() => r('HUNG'), 3000))]);
  await second;
  if (results[variant + (routed?'':' unrouted') + ' second finished'] === 'HUNG') { results.hungJson = (results.hungJson||[]).concat(await Promise.race([response.json().then(j => 'json ok:' + j.state, e => 'json error'), new Promise(r => setTimeout(() => r('json HUNG'), 3000))])); results.hungFinishedLater = (results.hungFinishedLater||[]).concat(await Promise.race([response.finished().then(() => 'finished later'), new Promise(r => setTimeout(() => r('still hung after +3s'), 3000))])); }
  if (results[variant + ' second finished'] !== 'HUNG') { const j = await response.json().then(j => j.state, () => 'json error'); results.okJson = results.okJson || {}; results.okJson[variant + ':' + j] = (results.okJson[variant + ':' + j] || 0) + 1; }
  const key = variant + (routed ? ' routed' : ' unrouted'); tally[key] = tally[key] || {hung: 0, ok: 0};
  tally[key][results[variant + (routed?'':' unrouted') + ' second finished'] === 'HUNG' ? 'hung' : 'ok']++;
  await page.close();
}
console.log(JSON.stringify({tally, okJson: results.okJson, hungJson: (results.hungJson||[]).length, hungStillAfter3s: (results.hungFinishedLater||[]).filter(x => x.startsWith("still")).length}));
await browser.close(); server.close();
