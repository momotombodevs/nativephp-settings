<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Stores;

use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;

/**
 * Used outside a NativePHP runtime in non-local environments, where settings
 * have nowhere to live. Every call fails like 1.0 did without a runtime.
 */
final class UnavailableStore implements SettingsStore
{
    public function get(string $key): mixed
    {
        self::unavailable();
    }

    public function has(string $key): bool
    {
        self::unavailable();
    }

    public function set(string $key, mixed $value): void
    {
        self::unavailable();
    }

    public function forget(string $key): void
    {
        self::unavailable();
    }

    public function all(): array
    {
        self::unavailable();
    }

    private static function unavailable(): never
    {
        throw new SettingsBridgeException(
            'BRIDGE_UNAVAILABLE',
            'NativePHP settings are only available inside a NativePHP runtime. Set NATIVE_SETTINGS_DRIVER=file to use a local file.',
        );
    }
}
