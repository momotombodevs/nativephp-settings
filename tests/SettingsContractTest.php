<?php

use InvalidArgumentException;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use Momotombo\NativephpSettings\Settings;

enum SettingsContractLanguage: string
{
    case Spanish = 'es';
}

enum SettingsContractPriority: int
{
    case High = 1;
}

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

function makeSettingsContract(): array
{
    $bridge = new SettingsContractBridge;

    return [new Settings($bridge), $bridge];
}

it('round trips scalar, enum, and nested values through the bridge contract', function () {
    [$settings, $bridge] = makeSettingsContract();

    $settings->set('profile.name', 'Ana');
    $settings->set('notifications.enabled', false);
    $settings->set('layout.columns', 0);
    $settings->set('display.scale', 1.25);
    $settings->set('app.language', SettingsContractLanguage::Spanish);
    $settings->set('profile.options', ['show_email' => true, 'shortcuts' => ['home', 'search']]);

    expect($settings->get('profile.name'))->toBe('Ana')
        ->and($settings->get('notifications.enabled'))->toBeFalse()
        ->and($settings->get('layout.columns'))->toBe(0)
        ->and($settings->get('display.scale'))->toBe(1.25)
        ->and($settings->get('app.language'))->toBe('es')
        ->and($settings->get('profile.options'))->toBe(['show_email' => true, 'shortcuts' => ['home', 'search']])
        ->and($settings->all())->toBe($bridge->values);

    expect($bridge->calls[0])->toBe([
        'method' => 'Settings.Set',
        'parameters' => ['key' => 'profile.name', 'value' => 'Ana'],
    ]);
});

it('returns a default for missing keys without storing the default', function () {
    [$settings, $bridge] = makeSettingsContract();

    $value = $settings->get('app.language', 'es');

    expect($value)->toBe('es')
        ->and($settings->has('app.language'))->toBeFalse()
        ->and($bridge->values)->toBe([]);
});

it('reports false and zero values as present', function () {
    [$settings] = makeSettingsContract();

    $settings->set('feature.enabled', false);
    $settings->set('layout.columns', 0);

    expect($settings->has('feature.enabled'))->toBeTrue()
        ->and($settings->has('layout.columns'))->toBeTrue()
        ->and($settings->get('feature.enabled'))->toBeFalse()
        ->and($settings->get('layout.columns'))->toBe(0);
});

it('forgets a setting repeatedly without changing other values', function () {
    [$settings] = makeSettingsContract();

    $settings->set('profile.name', 'Ana');
    $settings->set('app.language', 'es');
    $settings->forget('profile.name');
    $settings->forget('profile.name');

    expect($settings->has('profile.name'))->toBeFalse()
        ->and($settings->get('app.language'))->toBe('es');
});

it('rejects keys outside lowercase dot notation', function () {
    [$settings] = makeSettingsContract();

    foreach (['', 'Profile.name', 'two words', 'trailing.', '.leading', 'double..dot', 'dash-key', 'ñame'] as $key) {
        expect(fn () => $settings->set($key, 'value'))->toThrow(InvalidArgumentException::class);
    }
});

it('rejects unsupported and non-finite values before calling the bridge', function () {
    [$settings, $bridge] = makeSettingsContract();
    $resource = fopen('php://memory', 'r');

    try {
        foreach ([null, new stdClass, SettingsContractPriority::High, $resource, INF, NAN] as $value) {
            expect(fn () => $settings->set('app.value', $value))->toThrow(InvalidArgumentException::class);
        }
    } finally {
        fclose($resource);
    }

    expect($bridge->calls)->toBe([]);
});

it('accepts the maximum value size and nesting depth', function () {
    [$settings] = makeSettingsContract();
    $nested = 'leaf';

    for ($depth = 0; $depth < 32; $depth++) {
        $nested = [$nested];
    }

    $value = str_repeat('x', Settings::MAX_VALUE_BYTES - 2);
    $settings->set('app.max_value', $value);
    $settings->set('app.max_depth', $nested);

    expect($settings->get('app.max_value'))->toBe($value)
        ->and($settings->get('app.max_depth'))->toBe($nested);
});

it('rejects values beyond the encoded size and nesting limits', function () {
    [$settings, $bridge] = makeSettingsContract();
    $nested = 'leaf';

    for ($depth = 0; $depth < 33; $depth++) {
        $nested = [$nested];
    }

    expect(fn () => $settings->set('app.too_large', str_repeat('x', Settings::MAX_VALUE_BYTES - 1)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $settings->set('app.too_deep', $nested))
        ->toThrow(InvalidArgumentException::class)
        ->and($bridge->calls)->toBe([]);
});

it('throws a bridge exception when operation responses violate their contract', function () {
    [$settings, $bridge] = makeSettingsContract();

    $bridge->responses['Settings.Get'] = ['found' => 'yes'];
    expect(fn () => $settings->get('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.Has'] = [];
    expect(fn () => $settings->has('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.Set'] = ['status' => 'missing'];
    expect(fn () => $settings->set('app.value', 'value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.Forget'] = [];
    expect(fn () => $settings->forget('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.All'] = ['values' => 'not-an-array'];
    expect(fn () => $settings->all())->toThrow(SettingsBridgeException::class);
});

it('rejects contradictory and unsupported values returned by the bridge', function () {
    [$settings, $bridge] = makeSettingsContract();

    $bridge->responses['Settings.Get'] = ['found' => false, 'value' => 'unexpected'];
    expect(fn () => $settings->get('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.Get'] = ['found' => true];
    expect(fn () => $settings->get('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.Get'] = ['found' => true, 'value' => null];
    expect(fn () => $settings->get('app.value'))->toThrow(SettingsBridgeException::class);

    $bridge->responses['Settings.All'] = ['values' => ['Invalid.Key' => 'value']];
    expect(fn () => $settings->all())->toThrow(SettingsBridgeException::class);
});
