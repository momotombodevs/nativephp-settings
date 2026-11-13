# Changelog

All notable changes to `momotombo/nativephp-settings` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-12

### Added

- `Settings::fake(array $initial = [])` for tests, returning a `SettingsFake` with `assertSet()`, `assertForgotten()`, and `assertNothingSet()`. Keys and values are still validated while faked.
- Storage drivers behind a new `SettingsStore` contract: `native` (UserDefaults / SharedPreferences), `file` (JSON file with locked, atomic writes), and `array` (in memory).
- `config/nativephp-settings.php` (publish with `--tag=nativephp-settings-config`) with `driver` (`NATIVE_SETTINGS_DRIVER`, default `auto`), `file_path`, and `strict` (`NATIVE_SETTINGS_STRICT`).
- `auto` driver. On a device, native storage is always used and any other configured driver is ignored with a one-time warning, because `.env` ships inside the app build. Off the device: a custom bound `SettingsBridge` is used as before; unit tests (including those using NativePHP's `FakeBridge`) use an in-memory store; `native:jump` uses native storage when the bridge reports the settings functions, otherwise a file; the `local` and `testing` environments use a JSON file.
- `Settings::allWithReport()` returns the readable values plus the keys that were skipped.
- Typed getters: `getBool()`, `getInt()`, `getFloat()` (also accepts stored whole numbers), `getString()`, `getArray()` (lists and maps), and `getEnum()` (rehydrates backed enum cases). A type mismatch returns the default and logs the key, or throws `SettingTypeMismatch` when `strict` is enabled.
- `SettingsCorrupted` event, dispatched with the keys `Settings::all()` had to skip.
- The file store refuses files over 8 MiB, keeps file permissions on write, and renames a corrupt file to `<path>.corrupt-<YmdHis>` before replacing it.
- Native decoders reject oversized stored JSON before parsing it, and Android maps a stack overflow from deeply nested stored JSON to a corrupt entry instead of crashing.
- Native decoders read the future `mnp-settings:v2:` storage format (any JSON value), so data written by 2.x stays readable after a downgrade. Writes still use the 1.x format.
- JavaScript: `SettingsBridgeError` (with `code` and `status`), typed getters (`getBool`, `getInt`, `getFloat`, `getString`, `getArray`), `allWithReport()` exposing corrupt keys, `settings.d.ts` TypeScript types, `exports`/`types` in `package.json`, and import through a Vite alias.
- `LICENSE`, this changelog, a GitHub Actions workflow, and Orchestra Testbench-based package tests.

### Changed

- **Behavior change:** `Settings::all()` and the native `Settings.All` functions skip entries with an invalid key or an unreadable value instead of failing the whole read. Native code reports skipped keys in an optional `corrupt` list. Reading a single corrupt key with `get()` still throws `SettingsBridgeException`.
- `Settings` accepts a `SettingsStore` (passing a `SettingsBridge` still works).
- JavaScript: `set()` and `forget()` resolve `undefined` instead of the raw bridge payload; every rejection is a `SettingsBridgeError` (extends `Error`); invalid keys and `null` or non-finite values are rejected before any request; `all()` ignores corrupt entries (use `allWithReport()`); double-wrapped `{data: {data: …}}` responses, which cannot occur on nativephp/mobile ^4.5, are rejected.
- Keys with a `__proto__`, `constructor`, or `prototype` segment are rejected in PHP, Swift, Kotlin, and JavaScript, because those names are special in JavaScript objects. A segment such as `constructor_name` is still allowed.
- Outside a NativePHP runtime in environments other than `local` and `testing`, calls still throw `SettingsBridgeException` (`BRIDGE_UNAVAILABLE`) immediately, without trying to reach a device.
- Type-mismatch warnings from typed getters are logged once per key per process.
- JavaScript: `all()` and `allWithReport()` accept the list shape NativePHP's HTTP bridge produces for an empty or index-keyed map, so a fresh install no longer fails with `INVALID_RESPONSE`.
- Requires PHP `^8.3`, matching `nativephp/mobile` ^4.5.

### Removed

- The `copy_assets` manifest hook. The plugin ships no assets.
- The `nativephp:settings:copy-assets` Artisan command. It was the no-op command behind that hook; remove any scripts that call it.

### Upgrading

- Nothing is required to upgrade from 1.0. If code relied on `Settings::all()` throwing for corrupt data, use `allWithReport()` or listen for `SettingsCorrupted` instead.
- Off the device, the `local` and `testing` environments now store settings in `storage/app/nativephp-settings.json` instead of failing; other environments still fail with `BRIDGE_UNAVAILABLE`. Set `NATIVE_SETTINGS_DRIVER` explicitly to choose otherwise.
- Tests that script `Settings.*` responses on NativePHP's `FakeBridge` now get an in-memory store under the default `auto` driver. Set `config(['nativephp-settings.driver' => 'native'])` in those tests, or switch them to `Settings::fake()`.
- Keys with a `__proto__`, `constructor`, or `prototype` segment now throw `InvalidArgumentException`. Existing entries under such keys are reported as corrupt by `all()`.
- Prefer `Settings::fake()` over scripting `Settings.*` responses on NativePHP's `FakeBridge`. Version 2.0 is planned to change the bridge wire format, and tests written against `Settings::fake()` will keep working.

## [1.0.0] - 2026-10-02

### Added

- `Settings` facade with `set()`, `get()`, `has()`, `forget()`, and `all()` for device-local preferences.
- iOS (UserDefaults) and Android (SharedPreferences) bridge functions with identical key and value validation.
- JavaScript module for Vue, React, and Inertia frontends.

[1.1.0]: https://github.com/momotombodevs/nativephp-settings/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/momotombodevs/nativephp-settings/releases/tag/v1.0.0
