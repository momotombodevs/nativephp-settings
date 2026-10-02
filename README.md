# NativePHP Settings

Device-local settings for NativePHP Mobile applications. The package exposes one PHP facade and stores each value on the current device through the NativePHP bridge.

```php
use Momotombo\NativephpSettings\Facades\Settings;

Settings::set('app.language', 'es');

$language = Settings::get('app.language', 'es');

if (Settings::has('app.language')) {
    Settings::forget('app.language');
}

$all = Settings::all();
```

Use this package for small local preferences such as appearance, language, onboarding state, notification preferences, recent searches, and UI filters. It is not a database, API cache, remote configuration service, feature-flag system, authentication system, or synchronization layer. Server-authoritative data such as bookings, orders, balances, permissions, and coupon redemptions belongs in the backend. Values are stored as ordinary app data; do not store credentials, payment details, or other secrets here.

## Requirements

- PHP `^8.2`;
- NativePHP Mobile `^4.5`;
- Android and iOS support supplied by the consuming NativePHP application;
- Composer.

The Android and iOS sources implement the same bridge contract. The package still needs to be compiled and exercised in a consuming app on both platforms before a release is considered verified.

## Installation

For a published package:

```bash
composer require momotombo/nativephp-settings
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register momotombo/nativephp-settings
php artisan native:plugin:list
php artisan native:plugin:validate
php artisan native:run android
```

Laravel auto-discovers the service provider. NativePHP plugins must also be explicitly registered before their native code is compiled.

### Local development from the host app

When developing inside a NativePHP host app's `packages/` directory, configure a Composer path repository in the host application and require the local package. Do not copy generated native files into the host app manually; NativePHP compiles the registered plugin from this package.

After changing PHP, the manifest, Kotlin, Swift, or JavaScript:

```bash
composer dump-autoload
php artisan native:plugin:list --all
php artisan native:plugin:validate packages/momotombo/nativephp-settings --no-interaction
php artisan native:run android
```

Repeat with `php artisan native:run ios` for iOS validation.

## PHP API

```php
Settings::set(string $key, mixed $value): void;
Settings::get(string $key, mixed $default = null): mixed;
Settings::has(string $key): bool;
Settings::forget(string $key): void;
Settings::all(): array;
```

`get()` returns its caller-provided default when the key is absent. It does not save that default. `null` is permitted as the PHP default argument but is not a supported stored value, so absence and a stored value are never confused.

The facade delegates to an injectable `Momotombo\NativephpSettings\Contracts\SettingsBridge`. Applications and tests can replace that binding with a deterministic fake. Calls outside a NativePHP runtime fail with `SettingsBridgeException` (`BRIDGE_UNAVAILABLE`); malformed native responses and native operation errors also raise `SettingsBridgeException` instead of silently returning empty values.

## JavaScript API

The package includes an ES module at `resources/js/settings.js` for Vue, React, Inertia, or other Vite-based frontends. It is installed with Composer; no npm package is published. From an app entry point at `resources/js/app.js`, import it by its Composer-installed path:

```js
import { settings } from '../../vendor/momotombo/nativephp-settings/resources/js/settings.js';

await settings.set('app.language', 'es');

const language = await settings.get('app.language', 'es');
const enabled = await settings.has('notifications.enabled');
const values = await settings.all();

await settings.forget('app.language');
```

The module also exports the individual `set`, `get`, `has`, `forget`, and `all` functions. Each call returns a promise. JavaScript uses the same key and value contract as PHP; native validation errors reject the promise. A missing key returns the `get()` default without saving it.

## Keys and values

Use stable, namespaced keys:

```text
appearance.mode
app.language
notifications.enabled
onboarding.completed
map.radius_km
search.recent_queries
filters.cafe.wifi
```

Keys must be non-empty lowercase ASCII segments containing letters, digits, or underscores, separated by single dots. Empty segments, uppercase letters, whitespace, and punctuation are rejected. Renaming a released key requires a migration; do not silently change a key in application code.

Stored values support:

- strings;
- booleans;
- integers;
- finite floats;
- string-backed PHP enums, stored as their string value;
- JSON-safe arrays and maps whose leaves are those scalar types.

`null`, closures, resources, arbitrary objects, integer-backed enums, non-finite floats, invalid UTF-8, and unsupported nested values are rejected. Collections may be nested up to 32 levels, counting the outermost collection. One value's compact UTF-8 JSON representation may not exceed 65,536 bytes (64 KiB). Validation runs in PHP and again in the native implementations.

## Native bridge contract

Android and iOS implement the same functions, inputs, success payloads, and error behavior:

| Function | Input | Success payload |
| --- | --- | --- |
| `Settings.Set` | `{ "key": "...", "value": ... }` | `{ "status": "ok" }` |
| `Settings.Get` | `{ "key": "..." }` | `{ "found": true, "value": ... }` or `{ "found": false }` |
| `Settings.Has` | `{ "key": "..." }` | `{ "found": true }` or `{ "found": false }` |
| `Settings.Forget` | `{ "key": "..." }` | `{ "status": "ok" }` |
| `Settings.All` | `{}` | `{ "values": { ... } }` |

NativePHP returns the success payload directly. Bridge failures use NativePHP's structured error response and are surfaced by the PHP adapter. Missing keys are normal results, not bridge errors. `Set`, `Get`, `Has`, `Forget`, and `All` are declared together in `nativephp.json` and have matching Android and iOS implementations.

## Platform storage

### Android

Values are persisted in a private `SharedPreferences` file named for this package, under keys prefixed with `dev.momotombo.nativephp.settings.`. Booleans and integers use native preference types. Strings, doubles, and JSON collections use versioned internal string encodings so their types survive a round trip and malformed stored data can be reported. Writes and removals are committed before the bridge call succeeds.

### iOS

Values are persisted in `UserDefaults.standard` under the same `dev.momotombo.nativephp.settings.` key prefix. Booleans and integers use native preference values; strings, doubles, and JSON collections use the same versioned internal encodings as Android. Stored malformed or unsupported values produce a structured bridge error.

These storage details are internal and do not appear in the PHP API. Secure storage is not included. A future `SecureSettings` API should use Android Keystore-backed storage and iOS Keychain, and must remain separate from `Settings::all()`.

## Defaults and types: next phase

Declarative definitions are a follow-up phase:

```php
// config/native-settings.php

return [
    'appearance.mode' => [
        'default' => 'system',
        'type' => 'string',
    ],
    'notifications.enabled' => [
        'default' => true,
        'type' => 'bool',
    ],
];
```

When added, configured defaults should be returned for absent keys without being persisted automatically.

## Testing and validation

From this package directory:

```bash
composer install
composer test
```

Package tests should cover the API contract, validation, defaults, malformed bridge responses, native errors, and behavior outside NativePHP. Android and iOS validation must additionally cover process/scene restart, Activity/scene recreation, background/foreground transitions, missing keys, corrupted values, size limits, and identical responses.

Package tests and `native:plugin:validate` do not prove native persistence. Validate the registered package in a real NativePHP consumer app:

```bash
php artisan native:plugin:list --all
php artisan native:plugin:validate packages/momotombo/nativephp-settings --no-interaction
php artisan native:run android
php artisan native:run ios
```

On both platforms, manually save a value, restart the app, read it, delete it, and test a missing key.

## Development workflow

1. Change the contract in the manifest and PHP/native implementations together.
2. Keep an injectable bridge fake available for deterministic PHP checks.
3. Update package tests for the changed behavior.
4. Run `composer test`.
5. Run `native:plugin:validate` from the host app.
6. Build Android and iOS from the host app, then inspect behavior on each platform.
7. Update this README and the changelog when a changelog is present.
8. Commit only files belonging to this package.

Do not use `git add -A` from the host application because the package is nested inside a potentially dirty app repository.

## Follow-up roadmap

1. Add a reusable deterministic PHP bridge fake and API-focused package tests.
2. Verify Android persistence with `SharedPreferences`.
3. Verify iOS persistence with `UserDefaults`.
4. Verify strict key and value validation across platforms.
5. Add declarative defaults and key-specific type validation.
6. Add migrations for renamed keys.
7. Add change events without exposing sensitive values.
8. Add secure storage as a separate API.
9. Align version constraints and publish a stable release.

## License

MIT
