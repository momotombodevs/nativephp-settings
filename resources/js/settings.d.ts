/** A JSON-compatible value accepted by the native settings store. `null` is not a valid value; use `forget()` instead. */
export type SettingValue =
    | string
    | number
    | boolean
    | SettingValue[]
    | { [key: string]: SettingValue };

/** A PHP-style array: a list or a string-keyed map. */
export type SettingArray = SettingValue[] | { [key: string]: SettingValue };

export type SettingsErrorCode =
    | 'INVALID_KEY'
    | 'INVALID_VALUE'
    | 'INVALID_RESPONSE'
    | 'METHOD_NOT_FOUND'
    | 'FUNCTION_NOT_AVAILABLE'
    | 'BRIDGE_UNAVAILABLE'
    | 'BRIDGE_ERROR'
    | (string & {});

export class SettingsBridgeError extends Error {
    constructor(code: SettingsErrorCode, message: string, options?: { status?: number | null; cause?: unknown });
    readonly name: 'SettingsBridgeError';
    /** Machine-readable error code; codes reported by NativePHP or native code are passed through. */
    code: SettingsErrorCode;
    /** HTTP status of the bridge response, or `null` when no response was received or the call was rejected client-side. */
    status: number | null;
}

export interface SettingsReport {
    values: Record<string, SettingValue>;
    /** Keys whose stored values could not be decoded and were skipped. */
    corrupt: string[];
}

export function set(key: string, value: SettingValue): Promise<void>;

export function get(key: string): Promise<SettingValue | null>;
export function get<T extends SettingValue = SettingValue, D = T>(key: string, defaultValue: D): Promise<T | D>;

export function has(key: string): Promise<boolean>;
export function forget(key: string): Promise<void>;
export function all(): Promise<Record<string, SettingValue>>;
export function allWithReport(): Promise<SettingsReport>;

export function getBool(key: string): Promise<boolean>;
export function getBool<D>(key: string, defaultValue: D): Promise<boolean | D>;

export function getInt(key: string): Promise<number>;
export function getInt<D>(key: string, defaultValue: D): Promise<number | D>;

export function getFloat(key: string): Promise<number>;
export function getFloat<D>(key: string, defaultValue: D): Promise<number | D>;

export function getString(key: string): Promise<string>;
export function getString<D>(key: string, defaultValue: D): Promise<string | D>;

export function getArray(key: string): Promise<SettingArray>;
export function getArray<D>(key: string, defaultValue: D): Promise<SettingArray | D>;

export interface Settings {
    set: typeof set;
    get: typeof get;
    has: typeof has;
    forget: typeof forget;
    all: typeof all;
    allWithReport: typeof allWithReport;
    getBool: typeof getBool;
    getInt: typeof getInt;
    getFloat: typeof getFloat;
    getString: typeof getString;
    getArray: typeof getArray;
}

export const settings: Settings;

export default settings;
