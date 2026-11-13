## momotombo/nativephp-settings

Device-local settings for NativePHP Mobile applications. This package is for small user preferences, not secrets or server-authoritative data.

### Installation

Installing requires all of these steps; a plugin that is required but not registered does nothing:

```bash
composer require momotombo/nativephp-settings
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register momotombo/nativephp-settings
php artisan native:plugin:list
```

`native:plugin:list` must show the plugin as registered. Then ask the user to rebuild with `php artisan native:run` (never run it yourself).

### PHP Usage (Livewire/Blade)

Use the `Settings` facade. Prefer typed getters when reading values that drive UI state:

@verbatim
<code-snippet name="Using Settings Facade" lang="php">
use Momotombo\NativephpSettings\Facades\Settings;

Settings::set('app.language', 'es');

$language = Settings::getString('app.language', 'es');
$enabled = Settings::getBool('notifications.enabled', true);
$columns = Settings::getInt('layout.columns', 3);
$theme = Settings::getEnum('appearance.theme', Theme::Light);

$exists = Settings::has('app.language');
$all = Settings::all();

Settings::forget('app.language');
</code-snippet>
@endverbatim

### Available Methods

- `Settings::set(string $key, mixed $value): void`
- `Settings::get(string $key, mixed $default = null): mixed`
- `Settings::has(string $key): bool`
- `Settings::forget(string $key): void`
- `Settings::all(): array` (skips unreadable entries and dispatches `SettingsCorrupted` with their keys)
- `Settings::allWithReport(): array` (`['values' => ..., 'corrupt' => [...keys]]`)
- `Settings::getBool()`, `getInt()`, `getFloat()`, `getString()`, `getArray()`, `getEnum()`: take a required default and return it when the key is missing or holds another type (`getFloat` accepts whole numbers; `getArray` accepts lists and maps). With `NATIVE_SETTINGS_STRICT=true` they throw `SettingTypeMismatch` instead.

Keys use lowercase dot notation with non-empty segments. Stored values may be strings, booleans, integers, finite floats, string-backed enums, and JSON-safe arrays or maps up to 32 levels deep. `null` is not supported; each compact UTF-8 JSON value is limited to 65,536 bytes. Bridge or native storage failures throw `SettingsBridgeException`.

Storage driver: `NATIVE_SETTINGS_DRIVER` (`auto` by default). Devices always use native storage and ignore this setting. Off the device, `auto` uses memory in unit tests, a JSON file in `local`/`testing`, and otherwise throws `BRIDGE_UNAVAILABLE`. Tests that script `Settings.*` on `Native::fakeBridge()` must set the driver to `native`; prefer `Settings::fake()`.

Keys may not contain the segments `__proto__`, `constructor`, or `prototype`.

### Testing

Use `Settings::fake()` instead of scripting `Settings.*` responses on the NativePHP `FakeBridge`:

@verbatim
<code-snippet name="Faking Settings in Pest" lang="php">
$settings = Settings::fake(['app.language' => 'es']);

Native::visit('/settings')->tap('save-settings');

$settings->assertSet('app.language', 'en');
$settings->assertForgotten('profile.display_name');
$settings->assertNothingSet();
</code-snippet>
@endverbatim

### Privacy

Values are stored in UserDefaults / SharedPreferences, which are included in iCloud and Google backups and are not encrypted. Never store tokens, passwords, or sensitive personal data with this package; use NativePHP `SecureStorage` for secrets.

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using Settings in JavaScript" lang="javascript">
import { settings } from '../../vendor/momotombo/nativephp-settings/resources/js/settings.js';

await settings.set('app.language', 'es');
const language = await settings.get('app.language', 'es');
const exists = await settings.has('app.language');
const all = await settings.all();

await settings.forget('app.language');
</code-snippet>
@endverbatim
