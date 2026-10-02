/**
 * Local settings bridge for NativePHP Mobile.
 *
 * @example
 * import { settings } from '../../vendor/momotombo/nativephp-settings/resources/js/settings.js';
 *
 * await settings.set('app.language', 'es');
 * const language = await settings.get('app.language', 'es');
 */

const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (!response.ok || result.status === 'error') {
        throw new Error(result.message || 'Native settings call failed');
    }

    const nativeResponse = result.data;
    if (nativeResponse && nativeResponse.data !== undefined) {
        return nativeResponse.data;
    }

    return nativeResponse;
}

/**
 * Persist a supported value for a setting key.
 *
 * @param {string} key Lowercase dot-notation key.
 * @param {unknown} value Value supported by the PHP and native storage contract.
 * @returns {Promise<unknown>} Resolves with the NativePHP success payload.
 */
export async function set(key, value) {
    return bridgeCall('Settings.Set', { key, value });
}

/**
 * Return a stored value or the unsaved default when the key is absent.
 *
 * @param {string} key Lowercase dot-notation key.
 * @param {unknown} defaultValue Value returned when the key is absent.
 * @returns {Promise<unknown>}
 */
export async function get(key, defaultValue = null) {
    const response = await bridgeCall('Settings.Get', { key });
    return response.found ? response.value : defaultValue;
}

/**
 * Check whether a key exists, including when its value is false or zero.
 *
 * @param {string} key Lowercase dot-notation key.
 * @returns {Promise<boolean>}
 */
export async function has(key) {
    const response = await bridgeCall('Settings.Has', { key });
    return response.found;
}

/**
 * Remove a setting; removing a missing key succeeds.
 *
 * @param {string} key Lowercase dot-notation key.
 * @returns {Promise<unknown>} Resolves with the NativePHP success payload.
 */
export async function forget(key) {
    return bridgeCall('Settings.Forget', { key });
}

/**
 * Return every stored setting as an object keyed by setting key.
 *
 * @returns {Promise<Record<string, unknown>>}
 */
export async function all() {
    const response = await bridgeCall('Settings.All');
    return response.values;
}

export const settings = { set, get, has, forget, all };

export default settings;
