<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings;

use BackedEnum;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Events\SettingsCorrupted;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use Momotombo\NativephpSettings\Exceptions\SettingTypeMismatch;
use Momotombo\NativephpSettings\Stores\NativeStore;
use UnitEnum;

/**
 * Validates device-local preferences and delegates persistence to a {@see SettingsStore}.
 *
 * Values are normalized and validated before they reach the store and again
 * when they are read back; native implementations validate them once more
 * before persistence.
 */
final class Settings
{
    public const MAX_VALUE_BYTES = 65_536;

    private const MAX_NESTING_DEPTH = 32;

    /**
     * Key segments rejected on every platform because JavaScript treats them specially.
     */
    public const RESERVED_KEY_SEGMENTS = ['__proto__', 'constructor', 'prototype'];

    /**
     * Keys already reported by a type-mismatch warning in this process.
     *
     * @var array<string, true>
     */
    private static array $reportedTypeMismatches = [];

    private readonly SettingsStore $store;

    /**
     * Passing a {@see SettingsBridge} is still supported and wraps it in a {@see NativeStore}.
     *
     * @param  bool  $strict  When true, typed getters throw {@see SettingTypeMismatch} instead of returning the default.
     */
    public function __construct(SettingsStore|SettingsBridge $store, private readonly bool $strict = false)
    {
        $this->store = $store instanceof SettingsBridge ? new NativeStore($store) : $store;
    }

    /**
     * Persist a supported value under a lowercase, dot-separated key.
     *
     * @throws InvalidArgumentException When the key or value violates the storage contract.
     * @throws SettingsBridgeException When the store is unavailable or returns an invalid response.
     */
    public function set(string $key, mixed $value): void
    {
        $key = self::validateKey($key);
        $value = self::normalize($value);

        $this->store->set($key, $value);
    }

    /**
     * Retrieve a stored value or return the provided default when the key is absent.
     *
     * The default is returned as-is and is never persisted.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When the store is unavailable or returns an invalid value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->store->get(self::validateKey($key));

        if ($value === null) {
            return $default;
        }

        return self::normalizeStoredValue($value, 'Settings.Get');
    }

    /**
     * Read a boolean, or return the default when the key is absent or holds another type.
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not a boolean.
     */
    public function getBool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null || is_bool($value)) {
            return $value ?? $default;
        }

        $this->reportTypeMismatch($key, 'bool');

        return $default;
    }

    /**
     * Read an integer, or return the default when the key is absent or holds another type.
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not an integer.
     */
    public function getInt(string $key, int $default): int
    {
        $value = $this->get($key);

        if ($value === null || is_int($value)) {
            return $value ?? $default;
        }

        $this->reportTypeMismatch($key, 'int');

        return $default;
    }

    /**
     * Read a float. Whole numbers are accepted and cast, because a float such
     * as 2.0 can come back from storage as the integer 2.
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not a number.
     */
    public function getFloat(string $key, float $default): float
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        $this->reportTypeMismatch($key, 'float');

        return $default;
    }

    /**
     * Read a string, or return the default when the key is absent or holds another type.
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not a string.
     */
    public function getString(string $key, string $default): string
    {
        $value = $this->get($key);

        if ($value === null || is_string($value)) {
            return $value ?? $default;
        }

        $this->reportTypeMismatch($key, 'string');

        return $default;
    }

    /**
     * Read a list or map, or return the default when the key is absent or holds another type.
     *
     * @param  array<array-key, mixed>  $default
     * @return array<array-key, mixed>
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not an array.
     */
    public function getArray(string $key, array $default): array
    {
        $value = $this->get($key);

        if ($value === null || is_array($value)) {
            return $value ?? $default;
        }

        $this->reportTypeMismatch($key, 'array');

        return $default;
    }

    /**
     * Read a backed enum case of the default's enum class. Unknown cases and
     * values of the wrong backing type return the default.
     *
     * @template TEnum of BackedEnum
     *
     * @param  TEnum  $default
     * @return TEnum
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not a case of the enum.
     */
    public function getEnum(string $key, BackedEnum $default): BackedEnum
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        $enum = $default::class;
        $hasBackingType = is_string($default->value) ? is_string($value) : is_int($value);
        $case = $hasBackingType ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $this->reportTypeMismatch($key, $enum);

            return $default;
        }

        return $case;
    }

    /**
     * Determine whether a setting exists, including when its value is false or zero.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When the store is unavailable or returns an invalid response.
     */
    public function has(string $key): bool
    {
        return $this->store->has(self::validateKey($key));
    }

    /**
     * Remove a setting. Removing an absent key succeeds without changing other values.
     *
     * @throws InvalidArgumentException When the key does not use the supported format.
     * @throws SettingsBridgeException When the store is unavailable or returns an invalid response.
     */
    public function forget(string $key): void
    {
        $this->store->forget(self::validateKey($key));
    }

    /**
     * Retrieve all readable settings keyed by their validated setting keys.
     *
     * Entries with an invalid key or an unreadable value are skipped instead of
     * failing the whole read. Their keys (never their values) are logged and
     * reported through the {@see SettingsCorrupted} event.
     *
     * Numeric-only keys may appear as integer keys because PHP casts numeric
     * string array keys to integers.
     *
     * @return array<string|int, mixed>
     *
     * @throws SettingsBridgeException When the store is unavailable or returns a malformed response.
     */
    public function all(): array
    {
        return $this->allWithReport()['values'];
    }

    /**
     * Like {@see all()}, but also return the keys that were skipped.
     *
     * Skipped keys that use the supported key format can be removed with
     * {@see forget()}; keys in any other format cannot be addressed through this API.
     *
     * @return array{values: array<string|int, mixed>, corrupt: list<string>}
     *
     * @throws SettingsBridgeException When the store is unavailable or returns a malformed response.
     */
    public function allWithReport(): array
    {
        $stored = $this->store->all();
        $settings = [];
        $corrupt = $stored['corrupt'];

        foreach ($stored['values'] as $key => $value) {
            // PHP casts numeric-string array keys to integers during JSON decoding.
            $key = (string) $key;

            try {
                $settings[self::validateKey($key)] = self::normalize($value);
            } catch (InvalidArgumentException) {
                $corrupt[] = $key;
            }
        }

        $corrupt = array_values(array_unique($corrupt));

        if ($corrupt !== []) {
            $this->reportCorruptSettings($corrupt);
        }

        return ['values' => $settings, 'corrupt' => $corrupt];
    }

    /**
     * Validate and normalize a value into its stored representation
     * (string-backed enums become their string value).
     *
     * @internal Used by the package and its testing helpers.
     *
     * @throws InvalidArgumentException When the value violates the storage contract.
     */
    public static function normalize(mixed $value): mixed
    {
        $value = self::normalizeValue($value);
        self::assertValueSize($value);

        return $value;
    }

    /**
     * @param  list<string>  $keys
     */
    private function reportCorruptSettings(array $keys): void
    {
        Log::warning('Some NativePHP settings could not be read and were skipped.', ['keys' => $keys]);

        Event::dispatch(new SettingsCorrupted($keys));
    }

    /**
     * @throws SettingTypeMismatch In strict mode.
     */
    private function reportTypeMismatch(string $key, string $expected): void
    {
        if ($this->strict) {
            throw new SettingTypeMismatch($key, $expected);
        }

        if (isset(self::$reportedTypeMismatches[$key])) {
            return;
        }

        self::$reportedTypeMismatches[$key] = true;

        Log::warning('A NativePHP setting has an unexpected type; the default was returned.', [
            'key' => $key,
            'expected' => $expected,
        ]);
    }

    /**
     * @internal Resets the once-per-key type-mismatch warnings between tests.
     */
    public static function flushTypeMismatchWarnings(): void
    {
        self::$reportedTypeMismatches = [];
    }

    private static function validateKey(string $key): string
    {
        if ($key === ''
            || preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/D', $key) !== 1
            || array_intersect(explode('.', $key), self::RESERVED_KEY_SEGMENTS) !== []) {
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

    private static function normalizeStoredValue(mixed $value, string $method): mixed
    {
        try {
            return self::normalize($value);
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
}
