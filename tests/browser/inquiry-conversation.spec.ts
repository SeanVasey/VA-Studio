import { randomUUID } from 'node:crypto';
import { writeFile } from 'node:fs/promises';
import { expect, test, type BrowserContext } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';
import { fixtureOperation, type InquiryFixture, type OrderInquiryFixture } from './contact-inquiry-fixture';

test('visitor reads an in-app staff reply, retries one real follow-up and retains an archived conversation', async ({ page, browser }, testInfo) => {
  test.setTimeout(120_000); resetBrowserLoginRateLimit();
  const fixture: InquiryFixture = fixtureOperation('conversation-prepare', testInfo.project.name);
  let staff: BrowserContext | undefined; let foreign: BrowserContext | undefined;
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  const staffReply = `Synthetic in-app staff reply ${testInfo.project.name}`;
  const followUp = `Synthetic visitor follow-up ${testInfo.project.name}`;
  try {
    await page.goto('/contact');
    for (const label of ['Name', 'Email', 'Subject', 'Message'] as const) await page.getByLabel(new RegExp(`^${label}`)).fill(fixture.values[label.toLowerCase() as keyof InquiryFixture['values']]);
    const submitted = page.waitForResponse(response => response.url().endsWith('/contact/inquiries') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Send inquiry', exact: true }).click();
    const accepted = await submitted; expect(accepted.status()).toBe(201);
    const { receipt } = await accepted.json(); expect(receipt).toMatch(/^[a-f0-9-]{36}$/);
    const endpoint = `/contact/inquiries/${receipt}/conversation`;
    await page.getByRole('button', { name: 'Read replies and follow up', exact: true }).click();
    const conversation = page.getByRole('region', { name: 'Private inquiry conversation', exact: true });
    await expect(conversation.getByText(fixture.values.message, { exact: true })).toBeVisible();
    await expect(conversation.getByRole('status')).toBeFocused();
    expect((await page.request.get(endpoint)).headers()['cache-control']).toContain('no-store');
    foreign = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
    const denied = await foreign.request.get(endpoint); expect(denied.status()).toBe(404); expect(await denied.text()).not.toContain(fixture.values.message);
    const csrfDenied = await page.request.post(endpoint, { data: { message: followUp, requestKey: '11111111-2222-4333-8444-555555555555' } });
    expect(csrfDenied.status()).toBe(419); expect(csrfDenied.headers()['cache-control']).toContain('no-store');

    staff = await browser.newContext({ ...testInfo.project.use, baseURL: 'http://127.0.0.1:8173' });
    const operator = await staff.newPage(); operator.on('pageerror', error => errors.push(error.message));
    await operator.goto('/admin/login'); await operator.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
    await operator.getByLabel('Password', { exact: false }).and(operator.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
    await operator.getByRole('button', { name: 'Sign in', exact: true }).click(); await expect(operator).toHaveURL(/\/admin$/);
    await operator.goto(`/admin/customer-inquiries/${receipt}`);
    await operator.getByRole('button', { name: 'Reply in app', exact: true }).click();
    const replyDialog = operator.getByRole('dialog', { name: 'Reply in app', exact: true });
    await replyDialog.getByLabel('Reply message', { exact: false }).fill(staffReply);
    await replyDialog.getByRole('button', { name: 'Save reply', exact: true }).click(); await expect(replyDialog).not.toBeVisible();
    await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click();
    await expect(conversation.getByText(staffReply, { exact: true })).toBeVisible();

    const bodies: string[] = []; const messageIds: number[] = [];
    await page.route('**' + endpoint, async route => {
      if (route.request().method() !== 'POST') { await route.continue(); return; }
      bodies.push(route.request().postData()!);
      if (bodies.length !== 1) { await route.continue(); return; }
      const response = await route.fetch(); expect(response.status()).toBe(201);
      expect(response.headers()['cache-control']).toContain('no-store');
      messageIds.push((await response.json()).messageId);
      await route.abort('failed'); // The real service committed; only its first acknowledgement is lost.
    });
    await page.getByLabel('Follow-up message', { exact: true }).fill(followUp);
    await page.getByRole('button', { name: 'Send follow-up', exact: true }).focus(); await page.keyboard.press('Enter');
    await expect(conversation.getByRole('alert')).toContainText('could not confirm'); expect(bodies).toHaveLength(1);
    await expect(page.getByLabel('Follow-up message', { exact: true })).toHaveAttribute('readonly', '');
    const replayed = page.waitForResponse(response => new URL(response.url()).pathname === endpoint && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Retry same follow-up', exact: true }).click();
    const replay = await replayed; expect(replay.status()).toBe(200); expect(await replay.finished()).toBeNull();
    expect(replay.headers()['cache-control']).toContain('no-store');
    expect(replay.request().postData()).toBe(bodies[0]);
    const replaySaved = await replay.json(); expect(replaySaved).toEqual({ state: 'saved', messageId: messageIds[0] });
    messageIds.push(replaySaved.messageId);
    expect(bodies).toHaveLength(2); expect(bodies[1]).toBe(bodies[0]); expect(messageIds[1]).toBe(messageIds[0]);
    await expect(conversation.getByRole('status')).toHaveText('Your follow-up was saved. Replies appear here; no email is sent.');
    await expect(conversation.getByRole('list', { name: 'Conversation messages', exact: true }).getByText(followUp, { exact: true })).toBeVisible();
    const snapshot = await (await page.request.get(endpoint)).json();
    expect(snapshot.messages.filter((message: { sender: string }) => message.sender === 'you')).toEqual([expect.objectContaining({ id: messageIds[0], message: followUp })]);
    const stored = await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'));
    for (const value of [staffReply, followUp, fixture.values.message, JSON.parse(bodies[0]).requestKey]) expect(stored).not.toContain(value);

    // The existing Filament text entry includes sender and timestamp around each retained message.
    await operator.reload(); await expect(operator.getByText(followUp, { exact: false })).toBeVisible();
    await operator.getByRole('button', { name: 'Archive', exact: true }).click();
    const archive = operator.getByRole('alertdialog', { name: 'Archive', exact: true });
    await archive.getByRole('button', { name: 'Confirm', exact: true }).click(); await expect(archive).not.toBeVisible();
    await page.getByRole('button', { name: 'Refresh conversation', exact: true }).click();
    await expect(conversation.getByText('Inquiry archived', { exact: true })).toBeVisible();
    await expect(conversation.getByText(staffReply, { exact: true })).toBeVisible(); await expect(conversation.getByText(followUp, { exact: true })).toBeVisible();
    await expect(page.getByLabel('Follow-up message', { exact: true })).toHaveCount(0);
    const historyRequests: string[] = [];
    page.on('request', request => { if (new URL(request.url()).pathname.startsWith('/contact/inquiries/history')) historyRequests.push(request.url()); });
    await page.reload(); expect(historyRequests).toEqual([]);
    const history = page.getByRole('region', { name: 'Inquiries from this browser session', exact: true });
    await history.getByRole('button', { name: 'Show inquiries from this browser session', exact: true }).click();
    await expect(history.getByText(fixture.values.subject, { exact: true })).toBeVisible();
    await expect(history.getByRole('heading', { name: 'Saved inquiries', exact: true })).toBeFocused();
    expect(historyRequests).toHaveLength(1); expect(new URL(historyRequests[0]).search).toBe('');
    // A separate native request verifies the exact private DTO without relying on a renderer's response-body lifetime.
    const historyRead = await page.request.get('/contact/inquiries/history'); expect(historyRead.status()).toBe(200);
    expect(historyRead.headers()['cache-control']).toContain('no-store'); expect(historyRead.headers()['etag']).toBeUndefined();
    expect(await historyRead.json()).toEqual({ history: { inquiryHistorySchema: 1, limit: 20, nextCursor: null, inquiries: [{
      receipt, subject: fixture.values.subject, state: 'archived', createdAt: new Date(snapshot.original.createdAt).toISOString().replace('.000Z', 'Z'),
    }] } });
    const foreignHistory = await foreign.request.get('/contact/inquiries/history'); expect(foreignHistory.status()).toBe(200);
    expect(await foreignHistory.json()).toEqual({ history: { inquiryHistorySchema: 1, limit: 20, nextCursor: null, inquiries: [] } });
    const foreignCursor = await foreign.request.get('/contact/inquiries/history/before/' + receipt);
    const unknownCursor = await foreign.request.get('/contact/inquiries/history/before/00000000-0000-4000-8000-000000000001');
    expect(foreignCursor.status()).toBe(422); expect(unknownCursor.status()).toBe(422); expect(await foreignCursor.json()).toEqual(await unknownCursor.json());
    await history.getByRole('button', { name: `Open inquiry ${receipt}`, exact: true }).click();
    await expect(page.getByText('Inquiry archived', { exact: true })).toBeVisible();
    await expect(page.getByText(staffReply, { exact: true })).toBeVisible(); await expect(page.getByText(followUp, { exact: true })).toBeVisible();
    expect((await (await page.request.get(endpoint)).json()).messages).toEqual(snapshot.messages);
    // The original receipt-only opener remains an independent supported route into the same conversation.
    await page.reload(); await page.getByLabel('Inquiry receipt', { exact: true }).fill(receipt);
    await page.getByRole('button', { name: 'Open conversation', exact: true }).click();
    await expect(page.getByText('Inquiry archived', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('private-inquiry-conversation-archived.png'), fullPage: true });
    expect(errors).toEqual([]);

    // Extend the same original session without changing the generic inquiry's history or retry proof above.
    const linkedFixture = fixture as OrderInquiryFixture;
    expect(linkedFixture.orderSupport.capability).toMatch(/^[a-f0-9]{64}$/);
    await page.goto('/');
    const csrfCookie = (await page.context().cookies()).find(cookie => cookie.name === 'XSRF-TOKEN'); expect(csrfCookie).toBeTruthy();
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', Origin: 'http://127.0.0.1:8173',
      'X-XSRF-TOKEN': decodeURIComponent(csrfCookie!.value), 'X-Vasey-Order-Inquiry-Fixture': linkedFixture.orderSupport.capability };
    const quoted = await page.request.post('/quotes', { headers: { ...headers, 'Idempotency-Key': randomUUID() }, data: { items: linkedFixture.orderSupport.items } });
    expect(quoted.status()).toBe(200); const quoteId = (await quoted.json()).quote.id;
    const priced = await page.request.post(`/quotes/${quoteId}/pricing`, { headers, data: {} }); expect(priced.status()).toBe(200);
    const reviewed = await page.request.get(`/quotes/${quoteId}/order-review`, { headers }); expect(reviewed.status()).toBe(200);
    const review = (await reviewed.json()).review;
    const prepared = await page.request.post('/orders', { headers: { ...headers, 'Idempotency-Key': randomUUID() }, data: {
      quoteId, reviewHash: review.reviewHash, buyer: { legalName: 'Synthetic inquiry order buyer', email: 'inquiry-order@example.test' }, accepted: true,
    } });
    expect(prepared.status()).toBe(200); const orderId = (await prepared.json()).order.id;
    expect(fixtureOperation('conversation-order-retain', testInfo.project.name, undefined, undefined, JSON.stringify(orderId))).toEqual({ orderId, originalsRetained: true });
    const orderEndpoint = `/contact/inquiries/for-order/${orderId}`;
    const deniedSetup = await foreign.request.get(orderEndpoint); expect(deniedSetup.status()).toBe(404);
    expect(await deniedSetup.text()).not.toContain(orderId);
    const deniedSubmit = await page.request.post(orderEndpoint, { data: {} }); expect(deniedSubmit.status()).toBe(419);
    const setupRequests: string[] = [];
    page.on('request', request => { if (new URL(request.url()).pathname === orderEndpoint && request.method() === 'GET') setupRequests.push(request.url()); });
    await page.getByRole('button', { name: 'Open cart, 0 items', exact: true }).click();
    const cart = page.getByRole('dialog', { name: 'YOUR SELECTIONS.', exact: true });
    await cart.getByRole('button', { name: 'Browse test orders', exact: true }).click();
    await cart.getByRole('button', { name: `View test order status ${orderId}`, exact: true }).click(); expect(setupRequests).toEqual([]);
    const orderInquiry = cart.getByRole('region', { name: 'Test order inquiry', exact: true });
    await orderInquiry.getByRole('button', { name: 'Ask about this test order', exact: true }).press('Enter');
    await expect(orderInquiry.getByText(`Linked test order ${orderId}`, { exact: true })).toBeVisible(); expect(setupRequests).toHaveLength(1);
    for (const label of ['Name', 'Email', 'Subject', 'Message'] as const) await orderInquiry.getByLabel(new RegExp(`^${label}`)).fill(fixture.values[label.toLowerCase() as keyof InquiryFixture['values']]);
    const linkedBodies: string[] = []; const linkedReceipts: string[] = [];
    await page.route('**' + orderEndpoint, async route => {
      if (route.request().method() !== 'POST') { await route.continue(); return; }
      linkedBodies.push(route.request().postData()!);
      if (linkedBodies.length !== 1) { await route.continue(); return; }
      const result = await route.fetch(); expect(result.status()).toBe(201);
      expect(result.headers()['cache-control']).toContain('no-store'); const saved = await result.json();
      expect(saved).toEqual({ state: 'saved', receipt: expect.stringMatching(/^[a-f0-9-]{36}$/) }); linkedReceipts.push(saved.receipt);
      await route.abort('failed'); // Retry uses the original browser/server transport after the lost acknowledgement.
    });
    await orderInquiry.getByRole('button', { name: 'Send inquiry', exact: true }).press('Enter');
    await expect(orderInquiry.getByRole('alert')).toContainText('could not confirm'); await expect(orderInquiry.getByRole('alert')).toBeFocused();
    await expect(orderInquiry.getByLabel(/^Message/)).toHaveAttribute('readonly', '');
    expect(linkedBodies).toHaveLength(1); expect(Object.keys(JSON.parse(linkedBodies[0])).sort()).toEqual(['email', 'message', 'name', 'noticeToken', 'requestKey', 'subject', 'website']);
    const replayedInquiry = page.waitForResponse(result => new URL(result.url()).pathname === orderEndpoint && result.request().method() === 'POST');
    await orderInquiry.getByRole('button', { name: 'Retry same inquiry', exact: true }).click();
    const linkedReplay = await replayedInquiry; expect(linkedReplay.status()).toBe(200); expect(await linkedReplay.finished()).toBeNull();
    expect(linkedReplay.headers()['cache-control']).toContain('no-store');
    const linkedSaved = await linkedReplay.json(); expect(linkedSaved).toEqual({ state: 'saved', receipt: expect.stringMatching(/^[a-f0-9-]{36}$/) });
    linkedReceipts.push(linkedSaved.receipt);
    await expect(orderInquiry.getByRole('heading', { name: 'Inquiry saved', exact: true })).toBeVisible();
    expect(linkedBodies).toHaveLength(2); expect(linkedBodies[1]).toBe(linkedBodies[0]); expect(linkedReceipts).toEqual([linkedReceipts[0], linkedReceipts[0]]);
    const linkedReceipt = linkedReceipts[0], contextEndpoint = `/contact/inquiries/${linkedReceipt}/order-context`;
    const proof = fixtureOperation('conversation-order-verify', testInfo.project.name, linkedReceipt, 'new', linkedBodies[0]);
    expect(proof).toEqual({ orderId, receipt: linkedReceipt, singleInquiry: true, singleContext: true, originalsUnchanged: true, encryptedInput: true, rawNoticeTokenRetained: false });
    const contextRead = await page.request.get(contextEndpoint); expect(contextRead.status()).toBe(200); expect(contextRead.headers()['cache-control']).toContain('no-store');
    const expectedContext = { context: { orderInquiryContextSchema: 1, order: { id: orderId, testOnly: true } } }; expect(await contextRead.json()).toEqual(expectedContext);
    const foreignContext = await foreign.request.get(contextEndpoint); expect(foreignContext.status()).toBe(404); expect(await foreignContext.text()).not.toContain(orderId);
    expect(await (await page.request.get(`/contact/inquiries/${receipt}/order-context`)).json()).toEqual({ context: { orderInquiryContextSchema: 1, order: null } });
    await operator.goto(`/admin/customer-inquiries/${linkedReceipt}`);
    await expect(operator.getByText(`Linked test order ${orderId}. This retained reference does not confirm current payment, download access or usage rights.`, { exact: true })).toBeVisible();
    await page.goto('/contact'); await page.getByLabel('Inquiry receipt', { exact: true }).fill(linkedReceipt);
    await page.getByRole('button', { name: 'Open conversation', exact: true }).click();
    await expect(page.getByText(fixture.values.message, { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Read inquiry order reference', exact: true }).press('Enter');
    const reference = page.getByRole('region', { name: 'Inquiry order reference', exact: true });
    await expect(reference.getByText(orderId, { exact: true })).toBeVisible();
    await reference.getByRole('button', { name: 'Hide inquiry order reference', exact: true }).click();
    await expect(reference.getByRole('button', { name: 'Read inquiry order reference', exact: true })).toBeFocused(); await expect(reference.getByText(orderId, { exact: true })).toHaveCount(0);
    await reference.getByRole('button', { name: 'Read inquiry order reference', exact: true }).click(); await expect(reference.getByText(orderId, { exact: true })).toBeVisible();
    const savedValues = await page.evaluate(() => [...Object.values(localStorage), ...Object.values(sessionStorage)].join('\n'));
    for (const value of [orderId, fixture.values.message, fixture.values.email, JSON.parse(linkedBodies[0]).requestKey, JSON.parse(linkedBodies[0]).noticeToken]) expect(savedValues).not.toContain(value);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('private-order-inquiry-reference.png'), fullPage: true });
    expect(fixtureOperation('conversation-order-verify', testInfo.project.name, linkedReceipt, 'new', linkedBodies[0])).toEqual(proof);
    await writeFile(testInfo.outputPath('private-order-inquiry-proof.json'), JSON.stringify({ ...proof, exactRetry: true, foreignDenied: true, retainedContext: expectedContext, originalGenericJourneyPreserved: true }, null, 2));
    expect(errors).toEqual([]);
  } finally {
    try { await staff?.close(); await foreign?.close(); } finally { fixtureOperation('conversation-restore', testInfo.project.name); }
  }
});
