<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Momotombo\NativephpSettings\Bridge\NativePhpSettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Stores\ArrayStore;
use Momotombo\NativephpSettings\Stores\FileStore;
use Momotombo\NativephpSettings\Stores\NativeStore;
use Momotombo\NativephpSettings\Stores\StoreResolver;
use Momotombo\NativephpSettings\Stores\UnavailableStore;
use Momotombo\NativephpSettings\Support\RuntimeEnvironment;
use Momotombo\NativephpSettings\Tests\TestCase;
use Native\Mobile\Testing\Native;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/Support/SettingsContractBridge.php';

uses(TestCase::class);

beforeEach(function () {
    StoreResolver::flushWarnings();
});

function settingsEnvironment(
    bool $onDevice = false,
    bool $jump = false,
    bool $fakeBridge = false,
    bool $available = false,
    bool $unitTests = false,
    bool $local = false,
): RuntimeEnvironment {
    return new RuntimeEnvironment($onDevice, $jump, $fakeBridge, $available, $unitTests, $local);
}

function settingsResolver(?SettingsBridge $bridge = null): StoreResolver
{
    return new StoreResolver($bridge ?? new NativePhpSettingsBridge, sys_get_temp_dir().'/nativephp-settings-resolver.json');
}

it('resolves explicit drivers off the device', function (string $driver, string $store) {
    expect(settingsResolver()->resolve($driver, settingsEnvironment()))->toBeInstanceOf($store)
        ->and(settingsResolver()->resolve($driver, settingsEnvironment(fakeBridge: true, available: true, unitTests: true)))->toBeInstanceOf($store);
})->with([
    'native' => ['native', NativeStore::class],
    'file' => ['file', FileStore::class],
    'array' => ['array', ArrayStore::class],
]);

it('rejects unknown drivers, including on a device', function (RuntimeEnvironment $environment) {
    settingsResolver()->resolve('redis', $environment);
})->with([
    'off device' => [settingsEnvironment()],
    'on device' => [settingsEnvironment(onDevice: true, available: true)],
])->throws(InvalidArgumentException::class, 'Unsupported settings driver [redis]');

it('always uses native storage on a device', function (string $driver) {
    Log::spy();

    expect(settingsResolver()->resolve($driver, settingsEnvironment(onDevice: true, available: true, unitTests: true)))
        ->toBeInstanceOf(NativeStore::class);

    Log::shouldNotHaveReceived('warning');
})->with(['auto', 'native']);

it('ignores a non-native driver on a device and warns once per process', function () {
    Log::spy();
    $environment = settingsEnvironment(onDevice: true, available: true);

    expect(settingsResolver()->resolve('file', $environment))->toBeInstanceOf(NativeStore::class)
        ->and(settingsResolver()->resolve('array', $environment))->toBeInstanceOf(NativeStore::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'ignored on device') && $context === ['driver' => 'file'])
        ->once();
});

it('uses a custom bound bridge through native storage under auto', function () {
    expect(settingsResolver(new SettingsContractBridge)->resolve('auto', settingsEnvironment(unitTests: true)))
        ->toBeInstanceOf(NativeStore::class)
        ->and(settingsResolver(new SettingsContractBridge)->resolve('array', settingsEnvironment(unitTests: true)))
        ->toBeInstanceOf(ArrayStore::class);
});

it('uses an in-memory store for unit tests driven by a FakeBridge', function () {
    expect(settingsResolver()->resolve('auto', settingsEnvironment(fakeBridge: true, available: true, unitTests: true)))
        ->toBeInstanceOf(ArrayStore::class);
});

it('uses native storage through Jump when it provides the settings functions', function () {
    expect(settingsResolver()->resolve('auto', settingsEnvironment(jump: true, available: true, local: true)))
        ->toBeInstanceOf(NativeStore::class);
});

it('falls back with a warning when a relayed bridge lacks the settings functions', function (RuntimeEnvironment $environment, string $store) {
    Log::spy();

    expect(settingsResolver()->resolve('auto', $environment))->toBeInstanceOf($store);

    Log::shouldHaveReceived('warning')->once();
})->with([
    'jump' => [settingsEnvironment(jump: true, local: true), FileStore::class],
    'jump under tests' => [settingsEnvironment(jump: true, unitTests: true), ArrayStore::class],
]);

it('uses a local fallback without a bridge only in local and testing environments', function (RuntimeEnvironment $environment, string $store) {
    Log::spy();

    expect(settingsResolver()->resolve('auto', $environment))->toBeInstanceOf($store);

    Log::shouldNotHaveReceived('warning');
})->with([
    'local' => [settingsEnvironment(local: true), FileStore::class],
    'unit tests' => [settingsEnvironment(unitTests: true, local: true), ArrayStore::class],
    'production' => [settingsEnvironment(), UnavailableStore::class],
]);

it('fails every call outside a runtime in other environments', function () {
    $settings = new Settings(new UnavailableStore);

    expect(fn () => $settings->get('app.language'))->toThrow(SettingsBridgeException::class, 'only available inside a NativePHP runtime')
        ->and(fn () => $settings->set('app.language', 'es'))->toThrow(SettingsBridgeException::class)
        ->and(fn () => $settings->has('app.language'))->toThrow(SettingsBridgeException::class)
        ->and(fn () => $settings->forget('app.language'))->toThrow(SettingsBridgeException::class)
        ->and(fn () => $settings->all())->toThrow(SettingsBridgeException::class);

    try {
        $settings->get('app.language');
    } catch (SettingsBridgeException $exception) {
        expect($exception->bridgeCode)->toBe('BRIDGE_UNAVAILABLE');
    }
});

it('detects an off-device runtime without a bridge', function () {
    expect(RuntimeEnvironment::detect(true, true))->toEqual(settingsEnvironment(unitTests: true, local: true));
});

it('detects a test FakeBridge and its capabilities', function () {
    Native::fakeBridge();

    expect(RuntimeEnvironment::detect(true, false))->toEqual(settingsEnvironment(fakeBridge: true, available: true, unitTests: true));

    Native::fakeBridge()->withoutCapability('Settings.Get');

    expect(RuntimeEnvironment::detect(true, false))->toEqual(settingsEnvironment(fakeBridge: true, unitTests: true));
});

it('binds an in-memory store under unit tests and honours the configured driver', function () {
    Native::fakeBridge();

    expect(app(SettingsStore::class))->toBeInstanceOf(ArrayStore::class)
        ->and(app(Settings::class))->toBeInstanceOf(Settings::class);

    config(['nativephp-settings.driver' => 'native']);
    app()->forgetInstance(SettingsStore::class);

    expect(app(SettingsStore::class))->toBeInstanceOf(NativeStore::class);
});
