# NativePHP Settings

Device-local settings for NativePHP Mobile applications. Use it for small user preferences such as language, appearance, onboarding state, notification options, recent searches, and UI filters.

Settings are stored on the current device and are not synchronized with a server. Do not use this package for credentials, payment data, authentication state, or server-authoritative data.

## Requirements

- PHP `^8.2`
- NativePHP Mobile `^4.5`
- Composer
- Android and/or iOS support in the consuming NativePHP application

## Installation

```bash
composer require momotombo/nativephp-settings
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register momotombo/nativephp-settings
```

Confirm that NativePHP sees the plugin:

```bash
php artisan native:plugin:list
php artisan native:plugin:validate
```

Rebuild the native application after installing the plugin or changing native code:

```bash
php artisan native:run android
php artisan native:run ios
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
| `Settings::has(string $key): bool` | Check whether a key exists. |
| `Settings::forget(string $key): void` | Remove a value. Removing a missing key succeeds. |
| `Settings::all(): array` | Return all stored values. |

Calls require a NativePHP runtime. Outside the runtime, bridge failures, native storage failures, and malformed native responses throw `SettingsBridgeException`.

## JavaScript usage

The package includes an ES module for Vue, React, Inertia, and other Vite-based frontends. It is installed through Composer and does not publish an npm package.

From an app entry point such as `resources/js/app.js`:

```js
import { settings } from '../../vendor/momotombo/nativephp-settings/resources/js/settings.js';

await settings.set('app.language', 'es');

const language = await settings.get('app.language', 'en');
const enabled = await settings.has('notifications.enabled');
const all = await settings.all();

await settings.forget('app.language');
```

The module also exports the individual `set`, `get`, `has`, `forget`, and `all` functions. Each function returns a promise, and native validation failures reject that promise.

## Keys and values

Use stable, lowercase dot notation:

```text
appearance.mode
app.language
notifications.enabled
onboarding.completed
search.recent_queries
```

Keys contain non-empty ASCII segments made of lowercase letters, digits, or underscores, separated by single dots. Uppercase letters, whitespace, punctuation, and empty segments are rejected.

Supported stored values are:

- strings
- booleans
- integers
- finite floats
- string-backed PHP enums, stored as their string value
- JSON-safe arrays and maps containing those scalar values

`null`, closures, resources, arbitrary objects, integer-backed enums, non-finite floats, invalid UTF-8, and unsupported nested values are rejected. Collections may be nested up to 32 levels. Each value's compact UTF-8 JSON representation is limited to 65,536 bytes (64 KiB).

## Platform behavior

The same API and validation rules are implemented on Android and iOS. Values survive application restarts and are scoped to the local app installation. This package does not provide secure storage; use a dedicated Keychain/Keystore-backed solution for secrets.

## Running tests

Run the PHP contract and NativePHP app integration tests from the consuming app:

```bash
php artisan test
```

Run the JavaScript bridge tests from the consuming app:

```bash
npm --prefix packages/momotombo/nativephp-settings run test:js
```

The component tests fake the NativePHP bridge; they do not execute Kotlin or Swift storage. Verify device persistence and native rendering by running the app on Android and iOS.

## Validation before release

Run plugin validation from the consuming NativePHP application:

```bash
php artisan native:plugin:validate packages/momotombo/nativephp-settings --no-interaction
```

Then verify both platforms in a real app. Exercise `set`, `get`, `has`, `forget`, and `all`, including a missing key, an app restart, invalid input, and the 64 KiB boundary. NativePHP's plugin validation checks the manifest and source registration; it does not replace device testing.

## License

MIT
