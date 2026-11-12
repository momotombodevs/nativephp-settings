<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Stores;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Momotombo\NativephpSettings\Bridge\NativePhpSettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Support\RuntimeEnvironment;

/**
 * Builds the store for the configured driver (`auto`, `native`, `file`, or `array`).
 *
 * 1. On a device the store is always `native`, whatever the configured driver:
 *    `.env` ships inside the app build, so a development value such as
 *    `NATIVE_SETTINGS_DRIVER=file` would otherwise split PHP and JavaScript
 *    storage on the phone. A non-native driver is ignored with a warning.
 * 2. Off the device, `native`, `file`, and `array` are used as configured.
 * 3. `auto` off the device:
 *    a. A custom {@see SettingsBridge} bound by the app is used through `native` (1.0 behavior).
 *    b. Unit tests with a NativePHP test FakeBridge (not Jump): `array`, so
 *       tests never depend on scripted `Settings.*` responses. Configure
 *       `native` explicitly to exercise the bridge.
 *    c. `native:jump` or another relayed bridge: `native` when
 *       `nativephp_can('Settings.Get')` reports the plugin; otherwise the
 *       fallback store (`file`, or `array` under unit tests) with a warning.
 *    d. No bridge: `array` under unit tests, `file` in the `local` and
 *       `testing` environments, and otherwise a store that throws
 *       `BRIDGE_UNAVAILABLE` on every call, as 1.0 did.
 *
 * The store is resolved once per application instance (it is a container
 * singleton). After changing the driver at runtime, forget the
 * {@see SettingsStore} and {@see Settings}
 * instances, or use `Settings::fake()`, which always replaces the store.
 */
final class StoreResolver
{
    public const DRIVERS = ['auto', 'native', 'file', 'array'];

    private static bool $warnedAboutIgnoredDriver = false;

    public function __construct(
        private readonly SettingsBridge $bridge,
        private readonly string $filePath,
    ) {}

    /**
     * @throws InvalidArgumentException When the driver is not supported.
     */
    public function resolve(string $driver, RuntimeEnvironment $environment): SettingsStore
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(
                "Unsupported settings driver [{$driver}]. Use one of: ".implode(', ', self::DRIVERS).'.'
            );
        }

        if ($environment->onDevice) {
            if ($driver !== 'auto' && $driver !== 'native' && ! self::$warnedAboutIgnoredDriver) {
                self::$warnedAboutIgnoredDriver = true;

                Log::warning('The NativePHP settings driver is ignored on device; native storage is used.', ['driver' => $driver]);
            }

            return new NativeStore($this->bridge);
        }

        return match ($driver) {
            'native' => new NativeStore($this->bridge),
            'file' => new FileStore($this->filePath),
            'array' => new ArrayStore,
            default => $this->resolveAutomatically($environment),
        };
    }

    /**
     * @internal Resets the once-per-process warning between tests.
     */
    public static function flushWarnings(): void
    {
        self::$warnedAboutIgnoredDriver = false;
    }

    private function resolveAutomatically(RuntimeEnvironment $environment): SettingsStore
    {
        if (! $this->bridge instanceof NativePhpSettingsBridge) {
            return new NativeStore($this->bridge);
        }

        if ($environment->runningUnitTests && $environment->fakeBridgeBound && ! $environment->runningInJump) {
            return new ArrayStore;
        }

        if ($environment->runningInJump || $environment->fakeBridgeBound) {
            if ($environment->settingsBridgeAvailable) {
                return new NativeStore($this->bridge);
            }

            $fallback = $environment->runningUnitTests ? new ArrayStore : new FileStore($this->filePath);

            Log::warning('NativePHP settings bridge functions are unavailable in this runtime; using a local fallback store.', [
                'store' => $fallback::class,
            ]);

            return $fallback;
        }

        if ($environment->runningUnitTests) {
            return new ArrayStore;
        }

        return $environment->localEnvironment ? new FileStore($this->filePath) : new UnavailableStore;
    }
}
