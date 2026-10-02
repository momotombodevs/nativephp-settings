<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings;

use BackedEnum;
use InvalidArgumentException;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use UnitEnum;

/**
 * Stores device-local preferences through the NativePHP bridge.
 *
 * Values are normalized and validated before they cross the bridge; native
 * implementations validate them again before persistence.
 */
final class Settings
{
    public const MAX_VALUE_BYTES = 65_536;

    private const MAX_NESTING_DEPTH = 32;

    public function __construct(private readonly SettingsBridge $bridge) {}

    /**
     * Persist a supported value under a lowercase, dot-separated key.
     *
     * @throws InvalidArgumentException When the key or value violates the storage contract.
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function set(string $key, mixed $value): void
    {
        $key = self::validateKey($key);
        $value = self::normalizeValue($value);
        self::assertValueSize($value);

        $response = $this->bridge->call('Settings.Set', [
            'key' => $key,
            'value' => $value,
        ]);

        if (($response['status'] ?? null) !== 'ok') {
            self::invalidResponse('Settings.Set');
        }
    }

    /**
     * Retrieve a stored value or return the provided default when the key is absent.
     *
     * The default is returned as-is and is never persisted.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $response = $this->bridge->call('Settings.Get', ['key' => self::validateKey($key)]);

        if (! is_bool($response['found'] ?? null)) {
            self::invalidResponse('Settings.Get');
        }

        if (! $response['found']) {
            if (array_key_exists('value', $response)) {
                self::invalidResponse('Settings.Get');
            }

            return $default;
        }

        if (! array_key_exists('value', $response)) {
            self::invalidResponse('Settings.Get');
        }

        return self::normalizeBridgeValue($response['value'], 'Settings.Get');
    }

    /**
     * Determine whether a setting exists, including when its value is false or zero.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function has(string $key): bool
    {
        $response = $this->bridge->call('Settings.Has', ['key' => self::validateKey($key)]);

        if (! is_bool($response['found'] ?? null)) {
            self::invalidResponse('Settings.Has');
        }

        return $response['found'];
    }

    /**
     * Remove a setting. Removing an absent key succeeds without changing other values.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When NativePHP is unavailable or returns an invalid response.
     */
    public function forget(string $key): void
    {
        $response = $this->bridge->call('Settings.Forget', ['key' => self::validateKey($key)]);

        if (($response['status'] ?? null) !== 'ok') {
            self::invalidResponse('Settings.Forget');
        }
    }

    /**
     * Retrieve all stored settings keyed by their validated setting keys.
     *
     * Numeric-only keys may appear as integer keys because PHP casts numeric
     * string array keys to integers.
     *
     * @return array<string|int, mixed>
     * @throws SettingsBridgeException When NativePHP is unavailable or returns invalid data.
     */
    public function all(): array
    {
        $response = $this->bridge->call('Settings.All');
        $values = $response['values'] ?? null;

        if (! is_array($values)) {
            self::invalidResponse('Settings.All');
        }

        $settings = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                self::invalidResponse('Settings.All');
            }

            // PHP casts numeric-string array keys to integers during JSON decoding.
            $key = (string) $key;
            $settings[self::validateKey($key)] = self::normalizeBridgeValue($value, 'Settings.All');
        }

        return $settings;
    }

    private static function validateKey(string $key): string
    {
        if ($key === '' || preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Settings keys must use lowercase dot notation.');
        }

        return $key;
    }

    private static function normalizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new InvalidArgumentException('Settings values cannot be nested more than 32 levels.');
        }

        if ($value instanceof BackedEnum) {
            if (! is_string($value->value)) {
                throw new InvalidArgumentException('Only string-backed enums are supported as settings values.');
            }

            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            throw new InvalidArgumentException('Only string-backed enums are supported as settings values.');
        }

        if (is_string($value) || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Settings values must contain finite numbers.');
            }

            return $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_NESTING_DEPTH) {
                throw new InvalidArgumentException('Settings values cannot be nested more than 32 levels.');
            }

            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalizeValue($item, $depth + 1);
            }

            return $normalized;
        }

        if ($value === null) {
            throw new InvalidArgumentException('Null settings values are not supported.');
        }

        throw new InvalidArgumentException('Settings values must be strings, booleans, integers, floats, or JSON-safe arrays.');
    }

    private static function normalizeBridgeValue(mixed $value, string $method): mixed
    {
        try {
            $normalized = self::normalizeValue($value);
            self::assertValueSize($normalized);

            return $normalized;
        } catch (InvalidArgumentException $exception) {
            throw new SettingsBridgeException('INVALID_RESPONSE', "The {$method} response contains an unsupported settings value.", $exception);
        }
    }

    private static function assertValueSize(mixed $value): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Settings values must be representable as valid JSON.', previous: $exception);
        }

        if (strlen($json) > self::MAX_VALUE_BYTES) {
            throw new InvalidArgumentException('Settings values cannot exceed 65536 bytes when JSON encoded.');
        }
    }

    private static function invalidResponse(string $method): never
    {
        throw new SettingsBridgeException('INVALID_RESPONSE', "The {$method} response does not match the NativePHP Settings contract.");
    }
}
