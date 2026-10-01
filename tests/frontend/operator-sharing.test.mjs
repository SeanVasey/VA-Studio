import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/admin/operator-sharing.js', import.meta.url), 'utf8');
const publicLink = 'https://store.example.invalid/tracks/quiet-current';
const embedCode = '<iframe src="https://store.example.invalid/embed/tracks/quiet-current" title="VASEY.AUDIO tagged track preview"></iframe>';

function fixture({ clipboard, secure = true, navigator } = {}) {
    const calls = [];
    const trackLink = {
        value: publicLink, isConnected: true,
        focus() { calls.push('focus-link'); },
        select() { calls.push('select-link'); },
    };
    const embed = {
        value: embedCode, isConnected: true,
        focus() { calls.push('focus-embed'); },
        select() { calls.push('select-embed'); },
    };
    const root = { isConnected: true, contains: field => field === trackLink || field === embed };
    const state = runInNewContext(source, {
        navigator: navigator ?? { clipboard },
        isSecureContext: secure,
    });
    state.$el = root;
    state.$refs = { trackLink, embedCode: embed };
    return { state, calls, root, trackLink, embed };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });
    return { promise, resolve, reject };
}

test('copy success is reported only after the exact public link write fulfills', async () => {
    const write = deferred();
    const values = [];
    const { state } = fixture({ clipboard: { writeText(value) { values.push(value); return write.promise; } } });
    assert.equal(state.status, '');
    const copying = state.copy('link');
    assert.deepEqual(values, [publicLink]);
    assert.equal(state.copying, 'link');
    assert.equal(state.status, 'Copying link…');
    write.resolve();
    await copying;
    assert.equal(state.copying, null);
    assert.equal(state.status, 'Public link copied.');
});

test('embed copy writes the unchanged readonly code rather than the embed URL', async () => {
    const values = [];
    const { state } = fixture({ clipboard: { async writeText(value) { values.push(value); } } });
    await state.copy('embed');
    assert.deepEqual(values, [embedCode]);
    assert.equal(state.status, 'Embed code copied.');
});

test('a nonconforming clipboard result cannot claim a fulfilled write', async () => {
    for (const result of [undefined, null, 42, 'unexpected', {}]) {
        const { state } = fixture({ clipboard: { writeText() { return result; } } });
        await state.copy('link');
        assert.equal(state.copying, null);
        assert.equal(state.status, 'Clipboard is unavailable. Select the public link field and copy it manually.');
    }
});

test('readonly text remains data even when it contains quotes, Unicode and markup', async () => {
    const values = [];
    const { state, embed } = fixture({ clipboard: { async writeText(value) { values.push(value); } } });
    embed.value = '<iframe title="Écho & \'voice\'"></iframe></textarea><script>throw new Error("inert")</script>';
    await state.copy('embed');
    assert.deepEqual(values, [embed.value]);
    assert.equal(state.status, 'Embed code copied.');
});

test('a rejected clipboard write offers manual copying and never displays the browser error', async () => {
    const { state, calls } = fixture({ clipboard: { async writeText() { throw new Error('private browser/provider detail'); } } });
    await state.copy('link');
    assert.equal(state.copying, null);
    assert.equal(state.status, 'Clipboard is unavailable. Select the public link field and copy it manually.');
    assert.doesNotMatch(state.status, /private|provider/);
    assert.deepEqual(calls, [], 'late permission rejection does not steal focus');
});

test('missing Clipboard API, missing write method and insecure contexts have an honest fallback', async () => {
    for (const options of [{}, { clipboard: {} }, { secure: false, clipboard: { writeText() { assert.fail('insecure clipboard call'); } } }]) {
        const { state } = fixture(options);
        await state.copy('embed');
        assert.equal(state.copying, null);
        assert.equal(state.status, 'Clipboard is unavailable. Select the embed code field and copy it manually.');
    }
});

test('synchronous clipboard exceptions and throwing API accessors have the same manual fallback', async () => {
    const throwingNavigator = {};
    Object.defineProperty(throwingNavigator, 'clipboard', { get() { throw new Error('denied getter'); } });
    for (const options of [{ clipboard: { writeText() { throw new Error('denied call'); } } }, { navigator: throwingNavigator }]) {
        const { state } = fixture(options);
        await state.copy('link');
        assert.equal(state.copying, null);
        assert.equal(state.status, 'Clipboard is unavailable. Select the public link field and copy it manually.');
    }
});

test('repeat clicks cannot start another clipboard write before the active attempt settles', async () => {
    const write = deferred();
    const values = [];
    const { state, calls } = fixture({ clipboard: { writeText(value) { values.push(value); return write.promise; } } });
    const first = state.copy('link');
    await state.copy('embed');
    await state.copy('link');
    state.select('embed');
    assert.deepEqual(values, [publicLink]);
    assert.deepEqual(calls, []);
    assert.equal(state.copying, 'link');
    write.resolve();
    await first;
    await state.copy('embed');
    assert.deepEqual(values, [publicLink, embedCode]);
});

test('destroyed components cannot announce a late success or start another copy', async () => {
    const write = deferred();
    const values = [];
    const { state, calls } = fixture({ clipboard: { writeText(value) { values.push(value); return write.promise; } } });
    const pending = state.copy('link');
    state.destroy();
    write.resolve();
    await pending;
    await state.copy('embed');
    state.select('embed');
    assert.equal(state.status, '');
    assert.equal(state.copying, null);
    assert.deepEqual(values, [publicLink]);
    assert.deepEqual(calls, []);
});

test('destroyed components cannot announce a late failure', async () => {
    const write = deferred();
    const { state } = fixture({ clipboard: { writeText() { return write.promise; } } });
    const pending = state.copy('link');
    state.destroy();
    write.reject(new Error('late denial'));
    await pending;
    assert.equal(state.status, '');
    assert.equal(state.copying, null);
});

test('modal close invalidates an old callback without clearing a new pending attempt', async () => {
    const oldWrite = deferred();
    const newWrite = deferred();
    const { state } = fixture({ clipboard: { writeText(value) { return value === publicLink ? oldWrite.promise : newWrite.promise; } } });
    const oldPending = state.copy('link');
    state.cancel();
    const newPending = state.copy('embed');
    oldWrite.resolve();
    await oldPending;
    assert.equal(state.copying, 'embed');
    assert.equal(state.status, 'Copying embed code…');
    newWrite.resolve();
    await newPending;
    assert.equal(state.copying, null);
    assert.equal(state.status, 'Embed code copied.');
});

test('a disconnected root or removed field cannot receive late announcements', async () => {
    for (const detach of ['root', 'field', 'containment']) {
        const write = deferred();
        const { state, root, trackLink } = fixture({ clipboard: { writeText() { return write.promise; } } });
        const pending = state.copy('link');
        if (detach === 'root') root.isConnected = false;
        if (detach === 'field') trackLink.isConnected = false;
        if (detach === 'containment') root.contains = () => false;
        write.resolve();
        await pending;
        assert.equal(state.status, 'Copying link…');
        assert.equal(state.copying, null);
    }
});

test('missing, foreign and detached fields plus unknown kinds cannot write anything', async () => {
    for (const invalid of ['missing', 'foreign', 'detached', 'root', 'unknown']) {
        const { state, root, trackLink } = fixture({ clipboard: { writeText() { assert.fail('invalid copy'); } } });
        if (invalid === 'missing') delete state.$refs.trackLink;
        if (invalid === 'foreign') root.contains = () => false;
        if (invalid === 'detached') trackLink.isConnected = false;
        if (invalid === 'root') root.isConnected = false;
        await state.copy(invalid === 'unknown' ? 'private-master' : 'link');
        assert.equal(state.copying, null);
        assert.equal(state.status, '');
    }
});

test('manual selection focuses and selects the whole field without calling the Clipboard API', () => {
    const { state, calls } = fixture({ clipboard: { writeText() { assert.fail('manual selection writes nothing'); } } });
    state.select('link');
    assert.deepEqual(calls, ['focus-link', 'select-link']);
    assert.equal(state.status, 'Public link selected. Use your device’s copy command.');
    state.select('embed');
    assert.deepEqual(calls, ['focus-link', 'select-link', 'focus-embed', 'select-embed']);
    assert.equal(state.status, 'Embed code selected. Use your device’s copy command.');
});

test('manual selection errors use fixed text and do not expose raw DOM errors', () => {
    const { state, trackLink } = fixture();
    trackLink.select = () => { throw new Error('raw DOM detail'); };
    state.select('link');
    assert.equal(state.status, 'Select the field and copy it manually.');
});
