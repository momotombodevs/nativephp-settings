import assert from 'node:assert/strict';
import test from 'node:test';

import settings, {
    SettingsBridgeError,
    all,
    allWithReport,
    forget,
    get,
    getArray,
    getBool,
    getFloat,
    getInt,
    getString,
    has,
    set
} from './settings.js';

/**
 * Replace global fetch/document for one test. Each queued response is one of:
 * - an Error: fetch rejects with it (network failure);
 * - { status?, text }: a raw body string;
 * - { status?, body }: a JSON-encoded body.
 */
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

        const status = response.status ?? 200;
        const text = response.text ?? JSON.stringify(response.body);

        return {
            ok: status >= 200 && status < 300,
            status,
            text: async () => text
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

/** Wrap a native payload the way NativeCallController does on success. */
function ok(data) {
    return { body: { status: 'success', data } };
}

function bridgeError(code, status = null) {
    return (error) => {
        assert.ok(error instanceof SettingsBridgeError, `expected SettingsBridgeError, got ${error}`);
        assert.ok(error instanceof Error);
        assert.equal(error.name, 'SettingsBridgeError');
        assert.equal(error.code, code);
        assert.equal(error.status, status);

        return true;
    };
}

test('exports the same API through named, object, and default exports', () => {
    for (const [name, fn] of Object.entries({ set, get, has, forget, all, allWithReport, getBool, getInt, getFloat, getString, getArray })) {
        assert.equal(settings[name], fn, name);
    }
});

test('sends a set operation to the NativePHP bridge with its CSRF token and resolves undefined', async (t) => {
    const calls = fakeBridge(t, [ok({ status: 'ok' })]);

    assert.equal(await set('profile.name', 'Ana'), undefined);
    assert.equal(calls[0].url, '/_native/api/call');
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'csrf-token-value');
    assert.deepEqual(JSON.parse(calls[0].options.body), {
        method: 'Settings.Set',
        params: { key: 'profile.name', value: 'Ana' }
    });
});

test('preserves false and zero values and returns defaults for missing settings', async (t) => {
    fakeBridge(t, [
        ok({ found: true, value: false }),
        ok({ found: true, value: 0 }),
        ok({ found: false }),
        ok({ found: false })
    ]);

    assert.equal(await get('feature.enabled', true), false);
    assert.equal(await get('layout.columns', 4), 0);
    assert.equal(await get('app.language', 'es'), 'es');
    assert.equal(await get('app.language'), null);
});

test('checks existence, forgets keys, and returns all stored values', async (t) => {
    const calls = fakeBridge(t, [
        ok({ found: true }),
        ok({ status: 'ok' }),
        ok({ values: { 'app.language': 'es', 'feature.enabled': false } })
    ]);

    assert.equal(await has('feature.enabled'), true);
    assert.equal(await forget('feature.enabled'), undefined);
    assert.deepEqual(await all(), { 'app.language': 'es', 'feature.enabled': false });
    assert.deepEqual(calls.map(({ options }) => JSON.parse(options.body).method), [
        'Settings.Has',
        'Settings.Forget',
        'Settings.All'
    ]);
});

test('allWithReport exposes corrupt keys and defaults them to an empty list', async (t) => {
    fakeBridge(t, [
        ok({ values: { 'app.language': 'es' }, corrupt: ['broken.key'] }),
        ok({ values: {} }),
        ok({ values: { 'app.language': 'es' }, corrupt: ['broken.key'] })
    ]);

    assert.deepEqual(await allWithReport(), { values: { 'app.language': 'es' }, corrupt: ['broken.key'] });
    assert.deepEqual(await allWithReport(), { values: {}, corrupt: [] });
    assert.deepEqual(await all(), { 'app.language': 'es' });
});

test('maps a non-JSON body to INVALID_RESPONSE', async (t) => {
    fakeBridge(t, [
        { text: '<!doctype html><html><body>Server Error</body></html>' },
        { status: 500, text: '<html>Server Error</html>' }
    ]);

    await assert.rejects(get('app.value'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('app.value'), bridgeError('INVALID_RESPONSE', 500));
});

test('maps HTTP 404 to METHOD_NOT_FOUND, keeping the bridge message', async (t) => {
    fakeBridge(t, [
        { status: 404, body: { status: 'error', code: 'METHOD_NOT_FOUND', message: "Method 'Settings.Get' is not registered in the bridge registry" } },
        { status: 404, text: '<html>Not Found</html>' }
    ]);

    await assert.rejects(get('app.value'), (error) => {
        bridgeError('METHOD_NOT_FOUND', 404)(error);
        assert.match(error.message, /not registered/);

        return true;
    });
    await assert.rejects(get('app.value'), bridgeError('METHOD_NOT_FOUND', 404));
});

test('maps HTTP 503 to FUNCTION_NOT_AVAILABLE', async (t) => {
    fakeBridge(t, [
        { status: 503, body: { status: 'error', code: 'FUNCTION_NOT_AVAILABLE', message: 'nativephp_call function is not available' } }
    ]);

    await assert.rejects(has('app.value'), bridgeError('FUNCTION_NOT_AVAILABLE', 503));
});

test('maps a network failure to BRIDGE_UNAVAILABLE with the original cause', async (t) => {
    const networkError = new TypeError('Failed to fetch');
    fakeBridge(t, [networkError]);

    await assert.rejects(all(), (error) => {
        bridgeError('BRIDGE_UNAVAILABLE', null)(error);
        assert.equal(error.cause, networkError);

        return true;
    });
});

test('passes through error codes and messages from status:error bodies', async (t) => {
    fakeBridge(t, [
        { status: 400, body: { status: 'error', code: 'EXECUTION_FAILED', message: 'Storage is unavailable.', data: {} } },
        { status: 500, body: { status: 'error', code: 'EXECUTION_ERROR', message: 'Boom.' } },
        { status: 422, body: { message: 'Request rejected.' } }
    ]);

    await assert.rejects(set('app.value', 'x'), (error) => {
        bridgeError('EXECUTION_FAILED', 400)(error);
        assert.equal(error.message, 'Storage is unavailable.');

        return true;
    });
    await assert.rejects(forget('app.value'), bridgeError('EXECUTION_ERROR', 500));
    await assert.rejects(get('app.value'), bridgeError('BRIDGE_ERROR', 422));
});

test('rejects responses that do not match the bridge or settings contract', async (t) => {
    fakeBridge(t, [
        { body: { status: 'success', data: null } },
        { body: { status: 'success', data: 'raw native string' } },
        { body: { found: true, value: 1 } },
        { body: { status: 'success', data: { data: { found: true, value: 1 } } } },
        ok({ found: 'yes' }),
        ok({ found: true }),
        ok({}),
        ok({ status: 'saved' }),
        ok({}),
        ok({ values: 'not-a-map' }),
        ok({ values: {}, corrupt: 'broken.key' })
    ]);

    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(get('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(has('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(set('a', 1), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(forget('a'), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(all(), bridgeError('INVALID_RESPONSE', 200));
    await assert.rejects(allWithReport(), bridgeError('INVALID_RESPONSE', 200));
});

test('rejects invalid keys before calling the bridge', async (t) => {
    const calls = fakeBridge(t, []);

    for (const key of ['', 'App.Language', 'app..language', '.app', 'app.', 'app-language', 'app language', 'app.language\n', '__proto__', 'app.__proto__.polluted', 'constructor', 'app.prototype', 42, undefined]) {
        await assert.rejects(get(key), bridgeError('INVALID_KEY', null), String(key));
    }

    await assert.rejects(set('Bad', 'x'), bridgeError('INVALID_KEY', null));
    await assert.rejects(has('Bad'), bridgeError('INVALID_KEY', null));
    await assert.rejects(forget('Bad'), bridgeError('INVALID_KEY', null));
    await assert.rejects(getBool('Bad'), bridgeError('INVALID_KEY', null));
    assert.equal(calls.length, 0);
});

test('rejects null, undefined, and non-JSON values before calling the bridge', async (t) => {
    const calls = fakeBridge(t, []);

    for (const value of [null, undefined, Number.NaN, Number.POSITIVE_INFINITY, () => 1, Symbol('x'), 1n]) {
        await assert.rejects(set('app.value', value), bridgeError('INVALID_VALUE', null), String(value?.toString?.() ?? value));
    }

    assert.equal(calls.length, 0);
});

test('typed getters return the stored value when the type matches and the default otherwise', async (t) => {
    fakeBridge(t, [
        ok({ found: true, value: false }),
        ok({ found: true, value: 'true' }),
        ok({ found: false }),
        ok({ found: true, value: 3 }),
        ok({ found: true, value: 3.5 }),
        ok({ found: true, value: '3' }),
        ok({ found: true, value: 2.5 }),
        ok({ found: true, value: 2 }),
        ok({ found: true, value: '2.5' }),
        ok({ found: true, value: '' }),
        ok({ found: true, value: 7 }),
        ok({ found: true, value: [1, 2] }),
        ok({ found: true, value: { theme: 'dark' } }),
        ok({ found: true, value: 'list' }),
        ok({ found: false })
    ]);

    assert.equal(await getBool('a', true), false);
    assert.equal(await getBool('a', true), true);
    assert.equal(await getBool('a'), false);

    assert.equal(await getInt('a', 9), 3);
    assert.equal(await getInt('a', 9), 9);
    assert.equal(await getInt('a', 9), 9);

    assert.equal(await getFloat('a', 9), 2.5);
    assert.equal(await getFloat('a', 9), 2);
    assert.equal(await getFloat('a', 9), 9);

    assert.equal(await getString('a', 'x'), '');
    assert.equal(await getString('a', 'x'), 'x');

    assert.deepEqual(await getArray('a', ['d']), [1, 2]);
    assert.deepEqual(await getArray('a', ['d']), { theme: 'dark' });
    assert.deepEqual(await getArray('a', ['d']), ['d']);
    assert.deepEqual(await getArray('a'), []);
});

test('works without a document (no CSRF meta tag available)', async (t) => {
    const calls = fakeBridge(t, [ok({ found: false })]);
    delete globalThis.document;

    assert.equal(await has('app.value'), false);
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], '');
});

test('accepts reserved words inside a longer key segment', async (t) => {
    fakeBridge(t, [ok({ status: 'ok' }), ok({ status: 'ok' })]);

    assert.equal(await set('app.constructor_name', 'x'), undefined);
    assert.equal(await set('proto.prototypes', 'x'), undefined);
});

test('accepts the list shape NativeCallController produces for empty and index-keyed maps', async (t) => {
    // Real response bodies: PHP json_decode(assoc) + response()->json() turn {} into [] and {"0": …} into [ … ].
    fakeBridge(t, [
        { text: '{"status":"success","data":{"values":[]}}' },
        { text: '{"status":"success","data":{"values":[]}}' },
        { text: '{"status":"success","data":{"values":[],"corrupt":["broken.key"]}}' },
        { text: '{"status":"success","data":{"values":["zero","one"]}}' }
    ]);

    assert.deepEqual(await all(), {});
    assert.deepEqual(await allWithReport(), { values: {}, corrupt: [] });
    assert.deepEqual(await allWithReport(), { values: {}, corrupt: ['broken.key'] });
    assert.deepEqual(await all(), { 0: 'zero', 1: 'one' });
});
