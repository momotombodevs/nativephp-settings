# NativePHP Settings

[![Latest Version on Packagist](https://img.shields.io/packagist/v/momotombo/nativephp-settings.svg)](https://packagist.org/packages/momotombo/nativephp-settings)
[![Total Downloads](https://img.shields.io/packagist/dt/momotombo/nativephp-settings.svg)](https://packagist.org/packages/momotombo/nativephp-settings)
[![Tests](https://github.com/momotombodevs/nativephp-settings/actions/workflows/tests.yml/badge.svg)](https://github.com/momotombodevs/nativephp-settings/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/momotombo/nativephp-settings.svg)](LICENSE)

Device-local settings for NativePHP Mobile applications. Use it for small user preferences such as language, appearance, onboarding state, notification options, recent searches, and UI filters.

Settings are stored on the current device and are not synchronized with a server. Do not use this package for credentials, payment data, authentication state, or server-authoritative data.

## Requirements

- PHP `^8.3`
- NativePHP Mobile `^4.5`
- Android and/or iOS support in the consuming NativePHP application

## Installation

```bash
composer require momotombo/nativephp-settings
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register momotombo/nativephp-settings
```

The `vendor:publish` step creates `app/Providers/NativeServiceProvider.php` the first time you install a plugin; it is safe to run again. Confirm that NativePHP sees the plugin:

```bash
php artisan native:plugin:list
```

Rebuild the native application after installing the plugin or changing native code:

```bash
php artisan native:run android
php artisan native:run ios
```

Optionally publish the configuration file:

```bash
php artisan vendor:publish --tag=nativephp-settings-config
```

## PHP usage

```php
use Momotombo\NativephpSettings\Facades\Settings;

Settings::set('app.language', 'es');

$language = Settings::get('app.language', 'en');
$enabled = Settings::has('notifications.enabled');
$all = Settings::all();

Settings::forget('app.language');
```

### API

| Method | Description |
| --- | --- |
| `Settings::set(string $key, mixed $value): void` | Store or replace a value. |
| `Settings::get(string $key, mixed $default = null): mixed` | Read a value, or return the default when the key is absent. The default is not stored. |
| `Settings::has(string $key): bool` | Check whether a key exists, including when its value is `false` or `0`. |
| `Settings::forget(string $key): void` | Remove a value. Removing a missing key succeeds. |
| `Settings::all(): array` | Return all readable values. See [Corrupt entries](#corrupt-entries). |
| `Settings::allWithReport(): array` | Return `['values' => [...], 'corrupt' => [...]]`, including the keys that could not be read. |
| `Settings::getBool(string $key, bool $default): bool` | Typed read. |
| `Settings::getInt(string $key, int $default): int` | Typed read. |
| `Settings::getFloat(string $key, float $default): float` | Typed read. Whole numbers are accepted and cast. |
| `Settings::getString(string $key, string $default): string` | Typed read. |
| `Settings::getArray(string $key, array $default): array` | Typed read. Lists and maps are accepted. |
| `Settings::getEnum(string $key, BackedEnum $default): BackedEnum` | Returns the matching case of the default's enum. |

Calls require a NativePHP runtime, except in the `local` and `testing` environments, where settings fall back to a local file (see [Storage drivers](#storage-drivers)). Elsewhere, calls outside a runtime throw `SettingsBridgeException` with the `BRIDGE_UNAVAILABLE` code. Storage failures and malformed native responses also throw `SettingsBridgeException`. Invalid keys and values throw `InvalidArgumentException`.

### Typed getters

Typed getters return the default when the key is missing or the stored value has another type, so a preference written by an older app version never breaks a screen:

```php
enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
}

$columns = Settings::getInt('layout.columns', 3);
$scale = Settings::getFloat('display.scale', 1.0);
$theme = Settings::getEnum('appearance.theme', Theme::Light);
```

A mismatch is logged as a warning with the key and the expected type, never the value. Set `NATIVE_SETTINGS_STRICT=true` (or `strict` in the config file) to throw `SettingTypeMismatch` instead, which is useful while developing.

`getFloat()` accepts whole numbers because on a device a whole-number float such as `2.0` comes back as the integer `2`. The file store keeps `2.0` as a float, so `getInt()` on that value returns the default locally (or throws in strict mode) but the integer on a device. Store whole numbers as `int` to avoid the difference.

### Corrupt entries

`Settings::all()` skips entries it cannot read (for example a value written by hand or by another tool) and returns everything else. The skipped keys are logged as a warning (once per read, without values) and reported through the `SettingsCorrupted` event. Use `allWithReport()` to get them directly, for example to clean them up:

```php
['values' => $values, 'corrupt' => $corrupt] = Settings::allWithReport();

foreach ($corrupt as $key) {
    try {
        Settings::forget($key);
    } catch (InvalidArgumentException) {
        // Keys outside dot notation cannot be addressed through the API.
    }
}
```

Or listen for the event:

```php
use Illuminate\Support\Facades\Event;
use Momotombo\NativephpSettings\Events\SettingsCorrupted;

Event::listen(function (SettingsCorrupted $event): void {
    logger()->notice('Unreadable settings skipped.', ['keys' => $event->keys]);
});
```

Reading one corrupt key with `get()` still throws `SettingsBridgeException`.

## Storage drivers

The `driver` option (`NATIVE_SETTINGS_DRIVER`) selects where settings live off the device:

| Driver | Storage |
| --- | --- |
| `auto` (default) | See below. |
| `native` | UserDefaults on iOS, SharedPreferences on Android, through the NativePHP bridge. |
| `file` | A JSON file, `storage/app/nativephp-settings.json` by default (`NATIVE_SETTINGS_FILE_PATH`). |
| `array` | In memory, for the current PHP process only. |

**On a device, native storage is always used and the driver option is ignored** (a warning is logged once if it says otherwise). Your `.env` ships inside the app build, so a development value such as `NATIVE_SETTINGS_DRIVER=file` must never split PHP and JavaScript storage on a phone.

Off the device, `auto` resolves to:

- `native` when your app binds its own `SettingsBridge` implementation;
- `array` in unit tests, including tests that use NativePHP's `Native::visit()` or `Native::fakeBridge()` (set the driver to `native` in a test to exercise the bridge);
- `native` under `native:jump` when the bridge reports the settings functions, otherwise `file` with a warning;
- `file` in the `local` and `testing` environments;
- otherwise nothing: every call throws `SettingsBridgeException` (`BRIDGE_UNAVAILABLE`), as in 1.0.

`native:jump` relays bridge calls to the Jump app, which does not include third-party plugins. If settings calls fail under Jump, set `NATIVE_SETTINGS_DRIVER=file` in your local `.env`; the setting is ignored in device builds.

The store is resolved once per application instance. In a test, change the driver before the first settings call, or call `app()->forgetInstance()` for `SettingsStore` and `Settings` afterwards. `Settings::fake()` always replaces the store.

The file store locks writes and replaces the file atomically, keeping the file's permissions (0644 for a new file). A file that is not valid JSON, is nested too deeply, or exceeds 8 MiB is treated as empty and renamed to `<path>.corrupt-<timestamp>` before the next write.

## Testing

Replace settings storage with an in-memory fake:

```php
use Momotombo\NativephpSettings\Facades\Settings;

it('saves the selected language', function () {
    $settings = Settings::fake(['app.language' => 'es']);

    Native::visit('/settings')
        ->set('language', 'English')
        ->tap('save-settings');

    $settings->assertSet('app.language', 'en');
    $settings->assertForgotten('profile.display_name');
});
```

| Assertion | Passes when |
| --- | --- |
| `assertSet(string $key)` | The key was written at least once. |
| `assertSet(string $key, mixed $value)` | The key was written with that value (string-backed enums compare by value). |
| `assertForgotten(string $key)` | The key was forgotten. |
| `assertNothingSet()` | No key was written. |

Initial values passed to `fake()` are validated but do not count as writes. The fake still validates keys and values, so a test fails on the same input a device would reject.

## JavaScript usage

The package ships an ES module with TypeScript types. Point a Vite alias at the vendored copy:

```js
// vite.config.js
import path from 'node:path';

export default {
    resolve: {
        alias: {
            '@momotombo/nativephp-settings': path.resolve(__dirname, 'vendor/momotombo/nativephp-settings/resources/js/settings.js'),
        },
    },
};
```

```js
import { settings, SettingsBridgeError } from '@momotombo/nativephp-settings';

await settings.set('app.language', 'es');          // resolves undefined
const language = await settings.getString('app.language', 'en');
const columns = await settings.getInt('layout.columns', 3);
const enabled = await settings.getBool('feature.enabled');   // false when missing or not a boolean
const value = await settings.get('profile.name', null);      // untyped
await settings.has('feature.enabled');                       // true even when the value is false or 0
await settings.forget('feature.enabled');                    // forgetting a missing key succeeds

const values = await settings.all();
const { values: readable, corrupt } = await settings.allWithReport(); // corrupt: keys whose stored value could not be read

try {
    await settings.set('Bad Key', 'x');
} catch (error) {
    if (error instanceof SettingsBridgeError) {
        console.warn(error.code, error.status, error.message);
    }
}
```

Typed getters (`getBool`, `getInt`, `getFloat`, `getString`, `getArray`) return the default when the key is missing or the stored type does not match. `getFloat` also accepts whole numbers. `getArray` accepts lists and maps. `all()` and `allWithReport()` always return an object, even when NativePHP's HTTP bridge delivers the stored settings as an empty or index-keyed list.

Every failure rejects with a `SettingsBridgeError` (`code`, `status` for the HTTP status or `null`, `message`):

| Code | When |
|------|------|
| `INVALID_KEY` | Key is not lowercase dot notation; no request is sent |
| `INVALID_VALUE` | Value is `null`, `undefined`, a non-finite number, or not JSON-compatible; use `forget()` to remove a key |
| `BRIDGE_UNAVAILABLE` | The request to `/_native/api/call` could not be made |
| `METHOD_NOT_FOUND` | HTTP 404: the plugin is not registered or the native build is stale (rebuild the app) |
| `FUNCTION_NOT_AVAILABLE` | HTTP 503: not running inside a NativePHP runtime |
| `INVALID_RESPONSE` | Non-JSON body or a response that does not match the settings contract |
| other | Codes reported by NativePHP or native code are passed through (`BRIDGE_ERROR` if none) |

## Keys and values

Use stable, lowercase dot notation:

```text
appearance.mode
app.language
notifications.enabled
onboarding.completed
search.recent_queries
```

Keys contain non-empty ASCII segments made of lowercase letters, digits, or underscores, separated by single dots. Uppercase letters, whitespace, punctuation, and empty segments are rejected, as are the segments `__proto__`, `constructor`, and `prototype` (they are special in JavaScript objects).

Supported stored values are:

- strings
- booleans
- integers
- finite floats
- string-backed PHP enums, stored as their string value
- JSON-safe arrays and maps containing those scalar values

`null`, closures, resources, arbitrary objects, integer-backed enums, non-finite floats, invalid UTF-8, and unsupported nested values are rejected. Collections may be nested up to 32 levels. Each value's compact UTF-8 JSON representation is limited to 65,536 bytes (64 KiB).

Values pass through PHP arrays, so an empty map (`{}`) is read back as an empty list (`[]`), and a map whose keys are `"0"`, `"1"`, … is read back as a list. Treat both shapes as equivalent, or avoid empty and index-keyed maps.

## Platform behavior

The same API and validation rules are implemented on Android and iOS. Values survive application restarts and are scoped to the local app installation.

## Backups and privacy

Settings are stored in UserDefaults (iOS) and SharedPreferences (Android). Both are included in iCloud and Google device backups by default, so values can be restored onto another device and are readable by anyone with access to the backup. This package does not encrypt anything: never store tokens, passwords, or personal data that needs protection. Use a Keychain/Keystore-backed solution, such as NativePHP's `SecureStorage`, for secrets.

## Running tests

From the package directory:

```bash
composer install
composer test
composer format:check
npm run test:js
```

The PHP tests use Orchestra Testbench and fake the NativePHP bridge; they do not execute Kotlin or Swift storage. Verify device persistence by running the app on Android and iOS.

## Validation before release

Run plugin validation from the consuming NativePHP application:

```bash
php artisan native:plugin:validate packages/momotombo/nativephp-settings --no-interaction
```

Then verify both platforms in a real app. Exercise `set`, `get`, `has`, `forget`, and `all`, including a missing key, an app restart, invalid input, and the 64 KiB boundary. NativePHP's plugin validation checks the manifest and source registration; it does not replace device testing.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
