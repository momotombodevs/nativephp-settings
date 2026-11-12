<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Stores;

use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use Momotombo\NativephpSettings\Settings;

/**
 * Persists settings on the device through the NativePHP bridge
 * (UserDefaults on iOS, SharedPreferences on Android).
 *
 * This class only checks the shape of each bridge response; value validation
 * happens in {@see Settings}.
 */
final class NativeStore implements SettingsStore
{
    public function __construct(private readonly SettingsBridge $bridge) {}

    /**
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function get(string $key): mixed
    {
        $response = $this->bridge->call('Settings.Get', ['key' => $key]);

        if (! is_bool($response['found'] ?? null)) {
            self::invalidResponse('Settings.Get');
        }

        if (! $response['found']) {
            if (array_key_exists('value', $response)) {
                self::invalidResponse('Settings.Get');
            }

            return null;
        }

        if (! array_key_exists('value', $response) || $response['value'] === null) {
            self::invalidResponse('Settings.Get');
        }

        return $response['value'];
    }

    /**
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function has(string $key): bool
    {
        $response = $this->bridge->call('Settings.Has', ['key' => $key]);

        if (! is_bool($response['found'] ?? null)) {
            self::invalidResponse('Settings.Has');
        }

        return $response['found'];
    }

    /**
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function set(string $key, mixed $value): void
    {
        $response = $this->bridge->call('Settings.Set', ['key' => $key, 'value' => $value]);

        if (($response['status'] ?? null) !== 'ok') {
            self::invalidResponse('Settings.Set');
        }
    }

    /**
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function forget(string $key): void
    {
        $response = $this->bridge->call('Settings.Forget', ['key' => $key]);

        if (($response['status'] ?? null) !== 'ok') {
            self::invalidResponse('Settings.Forget');
        }
    }

    /**
     * Native implementations skip entries they cannot decode and report their
     * keys in the optional `corrupt` list.
     *
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function all(): array
    {
        $response = $this->bridge->call('Settings.All');
        $values = $response['values'] ?? null;
        $corrupt = $response['corrupt'] ?? [];

        if (! is_array($values) || ! is_array($corrupt)) {
            self::invalidResponse('Settings.All');
        }

        return [
            'values' => $values,
            'corrupt' => array_values(array_filter($corrupt, is_string(...))),
        ];
    }

    private static function invalidResponse(string $method): never
    {
        throw new SettingsBridgeException('INVALID_RESPONSE', "The {$method} response does not match the NativePHP Settings contract.");
    }
}
