<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Stores;

use Momotombo\NativephpSettings\Contracts\SettingsStore;

/**
 * In-memory store. Values live for the lifetime of the PHP process only.
 */
final class ArrayStore implements SettingsStore
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(private array $values = []) {}

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function all(): array
    {
        return ['values' => $this->values, 'corrupt' => []];
    }
}
