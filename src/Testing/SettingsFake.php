<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Testing;

use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Settings;
use PHPUnit\Framework\Assert;

/**
 * In-memory settings store that records writes for test assertions.
 *
 * Installed by {@see \Momotombo\NativephpSettings\Facades\Settings::fake()}: the real
 * {@see Settings} service keeps validating keys and values, so tests catch the same
 * mistakes a device would reject.
 */
final class SettingsFake implements SettingsStore
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<array{key: string, value: mixed}> */
    private array $writes = [];

    /** @var list<string> */
    private array $forgotten = [];

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
        $this->writes[] = ['key' => $key, 'value' => $value];
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
        $this->forgotten[] = $key;
    }

    public function all(): array
    {
        return ['values' => $this->values, 'corrupt' => []];
    }

    /**
     * Forget recorded writes and removals while keeping the current values.
     */
    public function clearRecorded(): void
    {
        $this->writes = [];
        $this->forgotten = [];
    }

    /**
     * Assert the key was set. When a value is given, at least one write must
     * match it after normalization (string-backed enums compare by value).
     */
    public function assertSet(string $key, mixed $value = null): self
    {
        $writes = array_values(array_filter($this->writes, fn (array $write): bool => $write['key'] === $key));

        Assert::assertNotEmpty($writes, "The setting [{$key}] was not set.");

        if (func_num_args() > 1) {
            $expected = Settings::normalize($value);

            Assert::assertContains(
                $expected,
                array_column($writes, 'value'),
                "The setting [{$key}] was set, but never to the expected value.",
            );
        }

        return $this;
    }

    public function assertForgotten(string $key): self
    {
        Assert::assertContains($key, $this->forgotten, "The setting [{$key}] was not forgotten.");

        return $this;
    }

    public function assertNothingSet(): self
    {
        Assert::assertSame(
            [],
            $this->writes,
            'Expected no settings to be set, but these were: '.implode(', ', array_unique(array_column($this->writes, 'key'))).'.',
        );

        return $this;
    }
}
