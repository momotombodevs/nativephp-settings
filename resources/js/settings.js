/**
 * Local settings bridge for NativePHP Mobile.
 *
 * @example
 * import { settings } from '@momotombo/nativephp-settings';
 *
 * await settings.set('app.language', 'es');
 * const language = await settings.getString('app.language', 'es');
 */

const baseUrl = '/_native/api/call';

const KEY_PATTERN = /^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/;

/** Key segments rejected on every platform because JavaScript treats them specially. */
const RESERVED_KEY_SEGMENTS = new Set(['__proto__', 'constructor', 'prototype']);

/**
 * Error raised for every failed settings call: invalid input, an unreachable
 * bridge, an error reported by NativePHP or native code, or a response that does
 * not match the settings contract.
 */
export class SettingsBridgeError extends Error {
    /**
     * @param {string} code Machine-readable error code, for example `INVALID_KEY`.
     * @param {string} message Human-readable description.
     * @param {{ status?: number | null, cause?: unknown }} [options]
     */
    constructor(code, message, { status = null, cause } = {}) {
        super(message, cause === undefined ? undefined : { cause });
        this.name = 'SettingsBridgeError';
        this.code = code;
        this.status = status;
    }
}

function isPlainObject(value) {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }

    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype || prototype === null;
}

function assertKey(key) {
    if (typeof key !== 'string' || !KEY_PATTERN.test(key) || key.split('.').some((segment) => RESERVED_KEY_SEGMENTS.has(segment))) {
        throw new SettingsBridgeError('INVALID_KEY', 'Settings keys must use lowercase dot notation.');
    }
}

function assertValue(value) {
    if (value === undefined || value === null) {
        throw new SettingsBridgeError('INVALID_VALUE', 'Settings values cannot be null or undefined; use forget() to remove a key.');
    }

    if (typeof value === 'number' && !Number.isFinite(value)) {
        throw new SettingsBridgeError('INVALID_VALUE', 'Settings values must be finite numbers.');
    }

    if (typeof value === 'function' || typeof value === 'symbol' || typeof value === 'bigint') {
        throw new SettingsBridgeError('INVALID_VALUE', `Settings values cannot be of type ${typeof value}.`);
    }
}

function csrfToken() {
    return globalThis.document?.querySelector?.('meta[name="csrf-token"]')?.content || '';
}

/**
 * POST a call to the NativePHP bridge endpoint and return the native payload.
 *
 * The endpoint (`Native\Mobile\Http\Controllers\NativeCallController`) answers
 * `{ status: 'success', data: <native payload> }` on success, where the native
 * payload is the bridge function's result returned unwrapped by
 * `BridgeResponse.success`. Failures answer `{ status: 'error', code, message }`
 * with HTTP 400 (missing method or native error), 404 (`METHOD_NOT_FOUND`),
 * 503 (`FUNCTION_NOT_AVAILABLE`) or 500 (`EXECUTION_ERROR`).
 *
 * @returns {Promise<{ payload: Record<string, unknown>, status: number | null }>}
 */
async function bridgeCall(method, params = {}) {
    let response;

    try {
        response = await fetch(baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: JSON.stringify({ method, params })
        });
    } catch (error) {
        throw new SettingsBridgeError('BRIDGE_UNAVAILABLE', `The NativePHP bridge could not be reached for ${method}.`, { cause: error });
    }

    const status = typeof response.status === 'number' ? response.status : null;
    let body;

    try {
        body = JSON.parse(await response.text());
    } catch (error) {
        body = undefined;
    }

    const errorBody = isPlainObject(body) && body.status === 'error' ? body : null;
    const message = typeof errorBody?.message === 'string' && errorBody.message !== '' ? errorBody.message : null;

    if (status === 404) {
        throw new SettingsBridgeError('METHOD_NOT_FOUND', message ?? `${method} is not registered in the NativePHP bridge.`, { status });
    }

    if (status === 503) {
        throw new SettingsBridgeError('FUNCTION_NOT_AVAILABLE', message ?? 'The NativePHP bridge is not available.', { status });
    }

    if (errorBody !== null || !response.ok) {
        if (body === undefined) {
            throw new SettingsBridgeError('INVALID_RESPONSE', `${method} failed with a non-JSON response.`, { status });
        }

        const code = typeof errorBody?.code === 'string' && errorBody.code !== '' ? errorBody.code : 'BRIDGE_ERROR';

        throw new SettingsBridgeError(code, message ?? `${method} failed.`, { status });
    }

    if (!isPlainObject(body) || body.status !== 'success' || !isPlainObject(body.data)) {
        throw new SettingsBridgeError('INVALID_RESPONSE', `The ${method} response does not match the NativePHP bridge contract.`, { status });
    }

    return { payload: body.data, status };
}

function invalidShape(method, status) {
    return new SettingsBridgeError('INVALID_RESPONSE', `The ${method} response does not match the NativePHP Settings contract.`, { status });
}

async function acknowledgedCall(method, params) {
    const { payload, status } = await bridgeCall(method, params);

    if (payload.status !== 'ok') {
        throw invalidShape(method, status);
    }
}

async function lookup(key) {
    assertKey(key);
    const { payload, status } = await bridgeCall('Settings.Get', { key });

    if (typeof payload.found !== 'boolean' || (payload.found && payload.value === undefined)) {
        throw invalidShape('Settings.Get', status);
    }

    return payload;
}

/**
 * Persist a supported value for a setting key.
 *
 * @param {string} key Lowercase dot-notation key.
 * @param {import('./settings').SettingValue} value Non-null JSON-compatible value.
 * @returns {Promise<void>}
 */
export async function set(key, value) {
    assertKey(key);
    assertValue(value);
    await acknowledgedCall('Settings.Set', { key, value });
}

/**
 * Return a stored value or the unsaved default when the key is absent.
 *
 * @param {string} key Lowercase dot-notation key.
 * @param {unknown} defaultValue Value returned when the key is absent.
 * @returns {Promise<unknown>}
 */
export async function get(key, defaultValue = null) {
    const payload = await lookup(key);

    return payload.found ? payload.value : defaultValue;
}

/**
 * Check whether a key exists, including when its value is false or zero.
 *
 * @param {string} key Lowercase dot-notation key.
 * @returns {Promise<boolean>}
 */
export async function has(key) {
    assertKey(key);
    const { payload, status } = await bridgeCall('Settings.Has', { key });

    if (typeof payload.found !== 'boolean') {
        throw invalidShape('Settings.Has', status);
    }

    return payload.found;
}

/**
 * Remove a setting; removing a missing key succeeds.
 *
 * @param {string} key Lowercase dot-notation key.
 * @returns {Promise<void>}
 */
export async function forget(key) {
    assertKey(key);
    await acknowledgedCall('Settings.Forget', { key });
}

/**
 * Return every readable setting plus the keys whose stored values are corrupt.
 *
 * @returns {Promise<{ values: Record<string, unknown>, corrupt: string[] }>}
 */
export async function allWithReport() {
    const { payload, status } = await bridgeCall('Settings.All');
    const values = normalizeValuesMap(payload.values);

    if (values === null) {
        throw invalidShape('Settings.All', status);
    }

    const corrupt = payload.corrupt ?? [];

    if (!Array.isArray(corrupt) || !corrupt.every((key) => typeof key === 'string')) {
        throw invalidShape('Settings.All', status);
    }

    return { values, corrupt };
}

/**
 * NativeCallController decodes the native payload into a PHP array and
 * re-encodes it, so a map whose keys are `"0"`, `"1"`, … (including the empty
 * map of a fresh install) arrives as a JSON list. Rebuild the map from the list.
 *
 * @returns {Record<string, unknown> | null} `null` when the value is neither a map nor a list.
 */
function normalizeValuesMap(values) {
    if (isPlainObject(values)) {
        return values;
    }

    if (Array.isArray(values)) {
        return Object.fromEntries(values.map((value, index) => [String(index), value]));
    }

    return null;
}

/**
 * Return every readable setting as an object keyed by setting key.
 *
 * @returns {Promise<Record<string, unknown>>}
 */
export async function all() {
    return (await allWithReport()).values;
}

async function typedGet(key, defaultValue, matches) {
    const payload = await lookup(key);

    return payload.found && matches(payload.value) ? payload.value : defaultValue;
}

/**
 * @param {string} key
 * @param {boolean} [defaultValue=false] Returned when the key is absent or not a boolean.
 * @returns {Promise<boolean>}
 */
export async function getBool(key, defaultValue = false) {
    return typedGet(key, defaultValue, (value) => typeof value === 'boolean');
}

/**
 * @param {string} key
 * @param {number} [defaultValue=0] Returned when the key is absent or not an integer.
 * @returns {Promise<number>}
 */
export async function getInt(key, defaultValue = 0) {
    return typedGet(key, defaultValue, (value) => Number.isInteger(value));
}

/**
 * @param {string} key
 * @param {number} [defaultValue=0] Returned when the key is absent or not a number; integers are accepted.
 * @returns {Promise<number>}
 */
export async function getFloat(key, defaultValue = 0) {
    return typedGet(key, defaultValue, (value) => typeof value === 'number' && Number.isFinite(value));
}

/**
 * @param {string} key
 * @param {string} [defaultValue=''] Returned when the key is absent or not a string.
 * @returns {Promise<string>}
 */
export async function getString(key, defaultValue = '') {
    return typedGet(key, defaultValue, (value) => typeof value === 'string');
}

/**
 * Return a stored list or map (a PHP array).
 *
 * @param {string} key
 * @param {unknown[] | Record<string, unknown>} [defaultValue=[]] Returned when the key is absent or not a list/map.
 * @returns {Promise<unknown[] | Record<string, unknown>>}
 */
export async function getArray(key, defaultValue = []) {
    return typedGet(key, defaultValue, (value) => Array.isArray(value) || isPlainObject(value));
}

export const settings = {
    set,
    get,
    has,
    forget,
    all,
    allWithReport,
    getBool,
    getInt,
    getFloat,
    getString,
    getArray
};

export default settings;
