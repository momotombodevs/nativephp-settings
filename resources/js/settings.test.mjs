import assert from 'node:assert/strict';
import test from 'node:test';

import settings, { all, forget, get, has, set } from './settings.js';

function fakeBridge(t, responses) {
    const originalFetch = globalThis.fetch;
    const originalDocument = globalThis.document;
    const calls = [];

    globalThis.document = {
        querySelector(selector) {
            assert.equal(selector, 'meta[name="csrf-token"]');

            return { content: 'csrf-token-value' };
        }
    };

    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });
        const response = responses.shift();

        if (response instanceof Error) {
            throw response;
        }

        if (response instanceof Function) {
            return response(url, options);
        }

        return {
            ok: response.ok ?? true,
            json: async () => response.body
        };
    };

    t.after(() => {
        if (originalFetch === undefined) {
            delete globalThis.fetch;
        } else {
            globalThis.fetch = originalFetch;
        }

        if (originalDocument === undefined) {
            delete globalThis.document;
        } else {
            globalThis.document = originalDocument;
        }
    });

    return calls;
}

test('exports the same API through named, object, and default exports', () => {
    assert.equal(settings.set, set);
    assert.equal(settings.get, get);
    assert.equal(settings.has, has);
    assert.equal(settings.forget, forget);
    assert.equal(settings.all, all);
});

test('sends a set operation to the NativePHP bridge with its CSRF token', async (t) => {
    const calls = fakeBridge(t, [{ body: { status: 'ok', data: { saved: true } } }]);

    assert.deepEqual(await set('profile.name', 'Ana'), { saved: true });
    assert.equal(calls[0].url, '/_native/api/call');
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-token-value');
    assert.deepEqual(JSON.parse(calls[0].options.body), {
        method: 'Settings.Set',
        params: { key: 'profile.name', value: 'Ana' }
    });
});

test('preserves false and zero values and returns defaults for missing settings', async (t) => {
    fakeBridge(t, [
        { body: { data: { found: true, value: false } } },
        { body: { data: { found: true, value: 0 } } },
        { body: { data: { found: false } } }
    ]);

    assert.equal(await get('feature.enabled', true), false);
    assert.equal(await get('layout.columns', 4), 0);
    assert.equal(await get('app.language', 'es'), 'es');
});

test('checks existence, forgets keys, and returns all stored values', async (t) => {
    const calls = fakeBridge(t, [
        { body: { data: { found: true } } },
        { body: { status: 'ok', data: { removed: true } } },
        { body: { data: { values: { 'app.language': 'es', 'feature.enabled': false } } } }
    ]);

    assert.equal(await has('feature.enabled'), true);
    assert.deepEqual(await forget('feature.enabled'), { removed: true });
    assert.deepEqual(await all(), { 'app.language': 'es', 'feature.enabled': false });
    assert.deepEqual(calls.map(({ options }) => JSON.parse(options.body).method), [
        'Settings.Has',
        'Settings.Forget',
        'Settings.All'
    ]);
});

test('rejects native error responses, failed HTTP responses, malformed JSON, and network errors', async (t) => {
    const bridge = fakeBridge(t, [
        { body: { status: 'error', message: 'Storage is unavailable.' } },
        { ok: false, body: { message: 'Request rejected.' } },
        () => ({ ok: true, json: async () => { throw new SyntaxError('Malformed JSON'); } }),
        new Error('Network unavailable.')
    ]);

    await assert.rejects(set('app.value', 'x'), /Storage is unavailable/);
    await assert.rejects(get('app.value'), /Request rejected/);
    await assert.rejects(has('app.value'), /Malformed JSON/);
    await assert.rejects(all(), /Network unavailable/);
    assert.equal(bridge.length, 4);
});
