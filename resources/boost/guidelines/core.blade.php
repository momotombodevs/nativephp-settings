## momotombo/nativephp-settings

Device-local settings for NativePHP Mobile applications. This package is for small user preferences, not secrets or server-authoritative data.

### Installation

```bash
composer require momotombo/nativephp-settings
php artisan native:plugin:register momotombo/nativephp-settings
```

### PHP Usage (Livewire/Blade)

Use the `Settings` facade:

@verbatim
<code-snippet name="Using Settings Facade" lang="php">
use Momotombo\NativephpSettings\Facades\Settings;

Settings::set('app.language', 'es');

$language = Settings::get('app.language', 'es');
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
- `Settings::all(): array`

Keys use lowercase dot notation with non-empty segments. Stored values may be strings, booleans, integers, finite floats, string-backed enums, and JSON-safe arrays or maps up to 32 levels deep. `null` is not supported; each compact UTF-8 JSON value is limited to 65,536 bytes. Calls require a NativePHP runtime and may throw `SettingsBridgeException` on bridge or native storage failures.

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
