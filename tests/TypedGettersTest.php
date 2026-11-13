<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingTypeMismatch;
use Momotombo\NativephpSettings\Facades\Settings as SettingsFacade;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Stores\ArrayStore;
use Momotombo\NativephpSettings\Tests\TestCase;

require_once __DIR__.'/TestCase.php';

uses(TestCase::class);

beforeEach(function () {
    Settings::flushTypeMismatchWarnings();
});

enum TypedGetterTheme: string
{
    case Light = 'light';
    case Dark = 'dark';
}

enum TypedGetterLevel: int
{
    case Low = 1;
    case High = 2;
}

/**
 * @param  array<string, mixed>  $values
 */
function typedSettings(array $values = [], bool $strict = false): Settings
{
    return new Settings(new ArrayStore($values), $strict);
}

it('returns the default for absent keys without logging', function () {
    Log::spy();
    $settings = typedSettings();

    expect($settings->getBool('a.key', true))->toBeTrue()
        ->and($settings->getInt('a.key', 7))->toBe(7)
        ->and($settings->getFloat('a.key', 1.5))->toBe(1.5)
        ->and($settings->getString('a.key', 'x'))->toBe('x')
        ->and($settings->getArray('a.key', ['x']))->toBe(['x'])
        ->and($settings->getEnum('a.key', TypedGetterTheme::Light))->toBe(TypedGetterTheme::Light);

    Log::shouldNotHaveReceived('warning');
});

it('returns stored values of the expected type', function () {
    $settings = typedSettings([
        'flag' => false,
        'count' => 0,
        'ratio' => 1.25,
        'whole' => 2,
        'name' => 'Ana',
        'list' => ['home', 'search'],
        'map' => ['show_tips' => true],
        'theme' => 'dark',
        'level' => 2,
    ]);

    expect($settings->getBool('flag', true))->toBeFalse()
        ->and($settings->getInt('count', 5))->toBe(0)
        ->and($settings->getFloat('ratio', 0.0))->toBe(1.25)
        ->and($settings->getFloat('whole', 0.0))->toBe(2.0)
        ->and($settings->getString('name', ''))->toBe('Ana')
        ->and($settings->getArray('list', []))->toBe(['home', 'search'])
        ->and($settings->getArray('map', []))->toBe(['show_tips' => true])
        ->and($settings->getEnum('theme', TypedGetterTheme::Light))->toBe(TypedGetterTheme::Dark)
        ->and($settings->getEnum('level', TypedGetterLevel::Low))->toBe(TypedGetterLevel::High);
});

it('returns the default and logs only the key when the type does not match', function (Closure $read, mixed $default) {
    Log::spy();
    $settings = typedSettings(['stored.value' => 'secret-value', 'stored.number' => 1]);

    expect($read($settings))->toBe($default);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => in_array($context['key'], ['stored.value', 'stored.number'], true)
            && ! str_contains($message.json_encode($context), 'secret-value'))
        ->once();
})->with([
    'bool' => [fn (Settings $settings) => $settings->getBool('stored.value', true), true],
    'int' => [fn (Settings $settings) => $settings->getInt('stored.value', 3), 3],
    'float' => [fn (Settings $settings) => $settings->getFloat('stored.value', 0.5), 0.5],
    'string' => [fn (Settings $settings) => $settings->getString('stored.number', 'x'), 'x'],
    'array' => [fn (Settings $settings) => $settings->getArray('stored.value', ['x']), ['x']],
    'unknown enum case' => [fn (Settings $settings) => $settings->getEnum('stored.value', TypedGetterTheme::Light), TypedGetterTheme::Light],
    'enum backing type' => [fn (Settings $settings) => $settings->getEnum('stored.number', TypedGetterTheme::Dark), TypedGetterTheme::Dark],
    'int enum backing type' => [fn (Settings $settings) => $settings->getEnum('stored.value', TypedGetterLevel::Low), TypedGetterLevel::Low],
]);

it('throws a type mismatch without the stored value in strict mode', function () {
    $settings = typedSettings(['stored.value' => 'secret-value'], strict: true);

    try {
        $settings->getInt('stored.value', 0);
        $this->fail('Expected a type mismatch.');
    } catch (SettingTypeMismatch $exception) {
        expect($exception->key)->toBe('stored.value')
            ->and($exception->expected)->toBe('int')
            ->and($exception->getMessage())->not->toContain('secret-value');
    }

    expect(fn () => $settings->getEnum('stored.value', TypedGetterTheme::Light))
        ->toThrow(SettingTypeMismatch::class, TypedGetterTheme::class)
        ->and($settings->getString('stored.value', ''))->toBe('secret-value')
        ->and($settings->getBool('absent.key', true))->toBeTrue();
});

it('reads the strict flag from config for the container and the fake', function () {
    config(['nativephp-settings.strict' => true]);
    app()->instance(SettingsStore::class, new ArrayStore(['stored.value' => 'text']));
    app()->forgetInstance(Settings::class);

    expect(fn () => app(Settings::class)->getBool('stored.value', false))->toThrow(SettingTypeMismatch::class);

    SettingsFacade::fake(['stored.value' => 'text']);

    expect(fn () => SettingsFacade::getBool('stored.value', false))->toThrow(SettingTypeMismatch::class);
});

it('logs a type mismatch once per key per process', function () {
    Log::spy();
    $settings = typedSettings(['stored.value' => 'text', 'other.value' => 'text']);

    $settings->getInt('stored.value', 0);
    $settings->getBool('stored.value', false);
    typedSettings(['stored.value' => 'text'])->getInt('stored.value', 0);
    $settings->getInt('other.value', 0);

    Log::shouldHaveReceived('warning')->twice();
});

it('keeps the enum type through the facade', function () {
    SettingsFacade::fake(['appearance.theme' => 'dark']);

    expect(SettingsFacade::getEnum('appearance.theme', TypedGetterTheme::Light))->toBe(TypedGetterTheme::Dark)
        ->and(SettingsFacade::getEnum('appearance.missing', TypedGetterLevel::Low))->toBe(TypedGetterLevel::Low);
});
