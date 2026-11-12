<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Contracts;

use Momotombo\NativephpSettings\Settings;

/**
 * Raw persistence for settings that have already been validated and normalized.
 *
 * Stores never validate keys or values themselves: {@see Settings}
 * validates writes before they reach a store and re-validates everything a store returns.
 * `null` is never a valid stored value, so `get()` uses it to signal an absent key.
 */
interface SettingsStore
{
    /**
     * Return the stored value, or null when the key is absent.
     */
    public function get(string $key): mixed;

    public function has(string $key): bool;

    /**
     * Persist a normalized value (string, bool, int, finite float, or JSON-safe array).
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove a key. Removing an absent key succeeds.
     */
    public function forget(string $key): void;

    /**
     * Return every stored entry plus the keys the store already knows it could not read.
     *
     * @return array{values: array<array-key, mixed>, corrupt: list<string>}
     */
    public function all(): array;
}
