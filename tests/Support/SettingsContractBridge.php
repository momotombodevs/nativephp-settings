<?php

declare(strict_types=1);

use Momotombo\NativephpSettings\Contracts\SettingsBridge;

/**
 * In-memory bridge that mimics the native Settings.* responses.
 */
final class SettingsContractBridge implements SettingsBridge
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, array{method: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>|Closure> */
    public array $responses = [];

    public function call(string $method, array $parameters = []): array
    {
        $this->calls[] = ['method' => $method, 'parameters' => $parameters];

        if (array_key_exists($method, $this->responses)) {
            $response = $this->responses[$method];

            return $response instanceof Closure ? $response($parameters, $this) : $response;
        }

        return match ($method) {
            'Settings.Set' => $this->set($parameters),
            'Settings.Get' => $this->get($parameters),
            'Settings.Has' => ['found' => array_key_exists($parameters['key'], $this->values)],
            'Settings.Forget' => $this->forget($parameters),
            'Settings.All' => ['values' => $this->values],
            default => throw new LogicException("Unexpected bridge call [{$method}]."),
        };
    }

    private function set(array $parameters): array
    {
        $this->values[$parameters['key']] = $parameters['value'];

        return ['status' => 'ok'];
    }

    private function get(array $parameters): array
    {
        $key = $parameters['key'];

        return array_key_exists($key, $this->values)
            ? ['found' => true, 'value' => $this->values[$key]]
            : ['found' => false];
    }

    private function forget(array $parameters): array
    {
        unset($this->values[$parameters['key']]);

        return ['status' => 'ok'];
    }
}
